import AVFoundation
import CoreImage
import Foundation
import ImageIO
import SpinKit

/// A felvételből kész videó: sebesség-ív, oda-vissza, keret + felirat, zene → H.264 MP4 (álló, 1080×1920).
///
/// Menete: a forrást egyszer, sorban olvassuk; a képkocka-terv (SpinKit.FramePlan) minden kimeneti kockához megmondja,
/// melyik forrás-pillanat kell, a `NearestFramePicker` pedig a hozzá legközelebbi kockát adja. Oda-vissza esetén
/// az előre menő kockák (keret nélkül) JPEG-ként a lemezre kerülnek, és a visszafelé menő rész ezekből áll.
/// A videó- és a hangsávot felváltva, időrendben írjuk (így az író nem akad el).
nonisolated struct VideoRenderer {
    @concurrent
    func render(_ request: RenderRequest, progress: @escaping @Sendable (Double) -> Void) async throws -> RenderResult {
        let settings = request.settings.sanitized()
        let width = request.outputWidth, height = request.outputHeight
        let fps = settings.outputFPS

        // 1. forrás
        let asset = AVURLAsset(url: request.source)
        guard let track = try await asset.loadTracks(withMediaType: .video).first else { throw RenderError.noVideoTrack }
        let (transform, timeRange) = try await track.load(.preferredTransform, .timeRange)
        let trackStart = timeRange.start.seconds.isFinite ? timeRange.start.seconds : 0
        let sourceDuration = timeRange.duration.seconds

        // 2. terv
        let plan = FramePlan.make(sourceDuration: sourceDuration, trimStart: settings.trimStart, trimEnd: settings.trimEnd,
                                  curve: settings.curve, outputFPS: fps, boomerang: settings.boomerang,
                                  maxDuration: settings.maxOutputSeconds)
        guard !plan.sourceTimes.isEmpty, let firstTime = plan.forwardTimes.first, let lastTime = plan.forwardTimes.last else {
            throw RenderError.emptyPlan
        }

        // 3. olvasó (csak a szükséges szakaszt)
        let reader = try AVAssetReader(asset: asset)
        let readerOutput = AVAssetReaderTrackOutput(track: track, outputSettings: [
            kCVPixelBufferPixelFormatTypeKey as String: kCVPixelFormatType_32BGRA,
            kCVPixelBufferIOSurfacePropertiesKey as String: [String: Any](),
        ])
        readerOutput.alwaysCopiesSampleData = false
        guard reader.canAdd(readerOutput) else { throw RenderError.reader("") }
        reader.add(readerOutput)
        let from = max(0, trackStart + firstTime - 0.25)
        let to = trackStart + lastTime + 0.25
        reader.timeRange = CMTimeRange(start: CMTime(seconds: from, preferredTimescale: 6000),
                                       end: CMTime(seconds: to, preferredTimescale: 6000))

        // 4. író
        try? FileManager.default.removeItem(at: request.output)
        let writer = try AVAssetWriter(outputURL: request.output, fileType: .mp4)
        writer.shouldOptimizeForNetworkUse = true
        let videoInput = AVAssetWriterInput(mediaType: .video, outputSettings: [
            AVVideoCodecKey: AVVideoCodecType.h264,
            AVVideoWidthKey: width,
            AVVideoHeightKey: height,
            AVVideoCompressionPropertiesKey: [
                AVVideoAverageBitRateKey: request.videoBitRate,
                AVVideoProfileLevelKey: AVVideoProfileLevelH264HighAutoLevel,
                AVVideoMaxKeyFrameIntervalKey: fps,
                AVVideoExpectedSourceFrameRateKey: fps,
            ] as [String: Any],
            AVVideoColorPropertiesKey: [
                AVVideoColorPrimariesKey: AVVideoColorPrimaries_ITU_R_709_2,
                AVVideoTransferFunctionKey: AVVideoTransferFunction_ITU_R_709_2,
                AVVideoYCbCrMatrixKey: AVVideoYCbCrMatrix_ITU_R_709_2,
            ],
        ])
        videoInput.expectsMediaDataInRealTime = false
        let adaptor = AVAssetWriterInputPixelBufferAdaptor(assetWriterInput: videoInput, sourcePixelBufferAttributes: [
            kCVPixelBufferPixelFormatTypeKey as String: kCVPixelFormatType_32BGRA,
            kCVPixelBufferWidthKey as String: width,
            kCVPixelBufferHeightKey as String: height,
            kCVPixelBufferIOSurfacePropertiesKey as String: [String: Any](),
        ])
        guard writer.canAdd(videoInput) else { throw RenderError.writer("videósáv") }
        writer.add(videoInput)

        // zene (ha van): a kész videó hosszára
        var music: MusicSource?
        var audioInput: AVAssetWriterInput?
        if let musicURL = request.music {
            let source = try await MusicSource.make(url: musicURL, start: settings.musicStart, volume: settings.musicVolume,
                                                    duration: plan.duration)
            let input = AVAssetWriterInput(mediaType: .audio, outputSettings: MusicSource.writerSettings)
            input.expectsMediaDataInRealTime = false
            guard writer.canAdd(input) else { throw RenderError.writer("hangsáv") }
            writer.add(input)
            music = source
            audioInput = input
        }

        guard reader.startReading() else { throw RenderError.reader(reader.error?.localizedDescription ?? "") }
        guard writer.startWriting() else { throw RenderError.writer(writer.error?.localizedDescription ?? "") }
        writer.startSession(atSourceTime: .zero)

        // 5. képkockák
        let workDir = FileManager.default.temporaryDirectory.appendingPathComponent("render-" + Token.make(10), isDirectory: true)
        try FileManager.default.createDirectory(at: workDir, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: workDir) }

        // Színkezelés nélkül: a videó értékei változatlanul mennek át (BT.709 be → BT.709 ki, nincs világosodás);
        // a keret és a felirat sRGB-értékei közvetlenül kerülnek rá (videószerkesztőkben is így szokás).
        let srgb = CGColorSpace(name: CGColorSpace.sRGB) ?? CGColorSpaceCreateDeviceRGB()
        let context = CIContext(options: [.workingColorSpace: NSNull(), .outputColorSpace: NSNull(), .cacheIntermediates: false])
        let frames = FrameSource(
            reader: reader, output: readerOutput, plan: plan, trackStart: trackStart,
            geometry: FrameGeometry(orientation: FrameGeometry.orientation(for: transform), width: width, height: height),
            cache: settings.boomerang ? FrameCache(directory: workDir, context: context) : nil)
        let overlay = OverlayBuilder.make(width: width, height: height, frame: request.frameImage,
                                          top: settings.captionTop, bottom: settings.captionBottom, color: settings.captionColor)
            .map { CIImage(cgImage: $0) }
        let outputRect = CGRect(x: 0, y: 0, width: width, height: height)
        let posterIndex = max(0, min(plan.forwardCount - 1, plan.forwardCount / 2))

        var written = 0
        var lastKey = -1
        var lastBuffer: CVPixelBuffer?
        var videoDone = false
        var audioDone = audioInput == nil
        var audioTime = 0.0
        var lastReported = -1.0
        let total = plan.sourceTimes.count

        do {
            while !(videoDone && audioDone) {
                try Task.checkCancellation()
                if writer.status == .failed { throw RenderError.writer(writer.error?.localizedDescription ?? "") }

                let videoTime = Double(written) / Double(fps)
                let videoReady = !videoDone && videoInput.isReadyForMoreMediaData
                let audioReady = !audioDone && (audioInput?.isReadyForMoreMediaData ?? false)
                let preferVideo = videoReady && (audioDone || videoTime <= audioTime + 0.2 || !audioReady)

                if preferVideo {
                    if let frame = try frames.next() {
                        let reuse = frame.key == lastKey ? lastBuffer : nil
                        var chosen = reuse
                        if reuse == nil || frame.index == posterIndex {
                            let image = (overlay.map { $0.composited(over: frame.image) } ?? frame.image).cropped(to: outputRect)
                            if frame.index == posterIndex {
                                Self.writePoster(image, to: request.poster, context: context, colorSpace: srgb)
                            }
                            if reuse == nil {
                                let fresh = try Self.makeBuffer(adaptor: adaptor)
                                context.render(image, to: fresh, bounds: outputRect, colorSpace: nil)
                                lastKey = frame.key
                                lastBuffer = fresh
                                chosen = fresh
                            }
                        }
                        guard let buffer = chosen else { throw RenderError.writer("képpuffer") }
                        let time = CMTime(value: CMTimeValue(frame.index), timescale: CMTimeScale(fps))
                        guard adaptor.append(buffer, withPresentationTime: time) else {
                            throw RenderError.writer(writer.error?.localizedDescription ?? "")
                        }
                        written += 1
                        let p = Double(written) / Double(max(total, 1))
                        if p - lastReported >= 0.01 || written == total {
                            lastReported = p
                            progress(min(p, 1))
                        }
                    } else {
                        if written == 0 { throw RenderError.noFrames }
                        videoInput.markAsFinished()
                        videoDone = true
                    }
                } else if audioReady, let music, let audioInput {
                    if let sample = music.output.copyNextSampleBuffer() {
                        guard audioInput.append(sample) else { throw RenderError.writer(writer.error?.localizedDescription ?? "") }
                        let end = CMTimeAdd(CMSampleBufferGetPresentationTimeStamp(sample), CMSampleBufferGetDuration(sample))
                        if end.isValid { audioTime = end.seconds }
                    } else {
                        if music.reader.status == .failed { throw RenderError.music(music.reader.error?.localizedDescription ?? "") }
                        audioInput.markAsFinished()
                        audioDone = true
                    }
                } else {
                    try await Task.sleep(for: .milliseconds(2))
                }
            }
        } catch {
            reader.cancelReading()
            music?.reader.cancelReading()
            writer.cancelWriting()
            try? FileManager.default.removeItem(at: request.output)
            throw error
        }

        await writer.finishWriting()
        guard writer.status == .completed else {
            throw RenderError.writer(writer.error?.localizedDescription ?? "")
        }
        let bytes = (try? FileManager.default.attributesOfItem(atPath: request.output.path)[.size] as? NSNumber)?.int64Value ?? 0
        progress(1)
        return RenderResult(duration: plan.duration, frames: written, bytes: bytes)
    }

    static func makeBuffer(adaptor: AVAssetWriterInputPixelBufferAdaptor) throws -> CVPixelBuffer {
        guard let pool = adaptor.pixelBufferPool else { throw RenderError.writer("nincs képpuffer") }
        var buffer: CVPixelBuffer?
        let status = CVPixelBufferPoolCreatePixelBuffer(kCFAllocatorDefault, pool, &buffer)
        guard status == kCVReturnSuccess, let buffer else { throw RenderError.writer("képpuffer: \(status)") }
        return buffer
    }

    static func writePoster(_ image: CIImage, to url: URL, context: CIContext, colorSpace: CGColorSpace) {
        let quality = CIImageRepresentationOption(rawValue: kCGImageDestinationLossyCompressionQuality as String)
        try? context.writeJPEGRepresentation(of: image, to: url, colorSpace: colorSpace, options: [quality: 0.86])
    }
}

/// A forráskocka útja a kimenetig: elforgatás (a felvétel iránya szerint) → kitöltés → kivágás a kimeneti méretre.
nonisolated struct FrameGeometry {
    let orientation: CGImagePropertyOrientation
    let width: Int
    let height: Int

    /// A videósáv transzformációjából a kép iránya (álló iPhone-felvétel: .right).
    static func orientation(for t: CGAffineTransform) -> CGImagePropertyOrientation {
        switch (t.a.rounded(), t.b.rounded(), t.c.rounded(), t.d.rounded()) {
        case (0, 1, -1, 0): .right
        case (0, -1, 1, 0): .left
        case (-1, 0, 0, -1): .down
        default: .up
        }
    }

    func image(from buffer: CVPixelBuffer) -> CIImage {
        var img = CIImage(cvPixelBuffer: buffer, options: [.colorSpace: NSNull()]).oriented(orientation)
        img = img.transformed(by: CGAffineTransform(translationX: -img.extent.minX, y: -img.extent.minY))
        let w = CGFloat(width), h = CGFloat(height)
        guard img.extent.width > 0, img.extent.height > 0 else { return img }
        let scale = max(w / img.extent.width, h / img.extent.height)
        let sourceRect = img.extent
        img = img.clampedToExtent()
        if scale < 0.75 {
            img = img.applyingFilter("CILanczosScaleTransform", parameters: [kCIInputScaleKey: scale, kCIInputAspectRatioKey: 1.0])
        } else {
            img = img.transformed(by: CGAffineTransform(scaleX: scale, y: scale))
        }
        let scaledWidth = sourceRect.width * scale, scaledHeight = sourceRect.height * scale
        img = img.transformed(by: CGAffineTransform(translationX: (w - scaledWidth) / 2, y: (h - scaledHeight) / 2))
        return img.cropped(to: CGRect(x: 0, y: 0, width: w, height: h))
    }
}

/// Az előre menő kockák JPEG-ben (keret nélkül) az oda-vissza részhez; azonos forráskocka csak egyszer.
nonisolated final class FrameCache {
    private let directory: URL
    private let context: CIContext
    private var fileForKey: [Int: URL] = [:]
    private var keyForIndex: [Int: Int] = [:]

    init(directory: URL, context: CIContext) {
        self.directory = directory
        self.context = context
    }

    func store(index: Int, key: Int, image: CIImage) throws {
        keyForIndex[index] = key
        if fileForKey[key] != nil { return }
        let url = directory.appendingPathComponent("f\(key).jpg")
        guard let space = CGColorSpace(name: CGColorSpace.sRGB) else { throw RenderError.writer("színtér") }
        let quality = CIImageRepresentationOption(rawValue: kCGImageDestinationLossyCompressionQuality as String)
        try context.writeJPEGRepresentation(of: image, to: url, colorSpace: space, options: [quality: 0.93])
        fileForKey[key] = url
    }

    func key(forIndex index: Int) -> Int? { keyForIndex[index] }

    func load(key: Int) throws -> CIImage {
        guard let url = fileForKey[key], let img = CIImage(contentsOf: url, options: [.colorSpace: NSNull()]) else {
            throw RenderError.reader("gyorstár")
        }
        return img
    }
}

/// A kimeneti kockák sorban: előre a forrásból (a legközelebbi kockával), utána – oda-vissza esetén – a gyorstárból fordítva.
nonisolated final class FrameSource {
    nonisolated struct Frame {
        /// Kimeneti sorszám.
        let index: Int
        /// Azonos forráskockánál azonos (az újrarajzolás megspórolható).
        let key: Int
        /// A kocka keret nélkül, kimeneti méretben.
        let image: CIImage
    }

    private let reader: AVAssetReader
    private let output: AVAssetReaderTrackOutput
    private let plan: FramePlan
    private let trackStart: Double
    private let geometry: FrameGeometry
    private let cache: FrameCache?
    private let reverse: [Int]
    private var picker: NearestFramePicker
    private var previous: CVPixelBuffer?
    private var queue: [Int] = []
    private var queuedImage: CIImage?
    private var queuedKey = -1
    private var nextKey = 0
    private var forwardEmitted = 0
    private var sourceFinished = false
    private var reversePosition = 0

    init(reader: AVAssetReader, output: AVAssetReaderTrackOutput, plan: FramePlan, trackStart: Double,
         geometry: FrameGeometry, cache: FrameCache?) {
        self.reader = reader
        self.output = output
        self.plan = plan
        self.trackStart = trackStart
        self.geometry = geometry
        self.cache = cache
        reverse = cache == nil ? [] : plan.reverseSourceIndices
        picker = NearestFramePicker(targets: plan.forwardTimes)
    }

    func next() throws -> Frame? {
        while forwardEmitted < plan.forwardCount {
            if let image = queuedImage, !queue.isEmpty {
                let index = queue.removeFirst()
                forwardEmitted += 1
                if let cache, index >= 1, index <= plan.forwardCount - 2 {
                    try cache.store(index: index, key: queuedKey, image: image)
                }
                return Frame(index: index, key: queuedKey, image: image)
            }
            if sourceFinished { break }
            if let sample = output.copyNextSampleBuffer() {
                guard let buffer = CMSampleBufferGetImageBuffer(sample) else { continue }
                let time = CMSampleBufferGetPresentationTimeStamp(sample).seconds - trackStart
                enqueue(picker.feed(time))
                previous = buffer
            } else {
                if reader.status == .failed { throw RenderError.reader(reader.error?.localizedDescription ?? "") }
                sourceFinished = true
                enqueue(picker.finish())
            }
        }
        // visszafelé (ha az előre menő részből egy kocka sem jött, nincs mit visszafelé játszani)
        guard forwardEmitted > 0, reversePosition < reverse.count, let cache else { return nil }
        let index = plan.forwardCount + reversePosition
        let sourceIndex = reverse[reversePosition]
        reversePosition += 1
        guard let key = cache.key(forIndex: sourceIndex) else { throw RenderError.reader("hiányzó kocka") }
        return Frame(index: index, key: key, image: try cache.load(key: key))
    }

    private func enqueue(_ range: Range<Int>) {
        guard !range.isEmpty, let previous else { return }
        queue.append(contentsOf: range)
        queuedImage = geometry.image(from: previous)
        queuedKey = nextKey
        nextKey += 1
    }
}
