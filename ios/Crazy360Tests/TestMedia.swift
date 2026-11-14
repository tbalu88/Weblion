import AVFoundation
import CoreVideo
import Foundation

/// Mesterséges felvételek és zene a tesztekhez, és a kész videó kockáinak visszaolvasása.
enum TestMedia {
    struct RGB: Equatable {
        var r: Int, g: Int, b: Int
    }

    static func tempURL(_ name: String) -> URL {
        FileManager.default.temporaryDirectory.appendingPathComponent("t-\(UUID().uuidString.prefix(8))-\(name)")
    }

    /// H.264 .mov: `frames` kocka `fps` időzítéssel; minden kocka bal és jobb fele egy-egy színnel.
    static func makeVideo(width: Int, height: Int, fps: Int32, frames: Int, transform: CGAffineTransform = .identity,
                          color: (Int) -> (left: RGB, right: RGB)) async throws -> URL {
        let url = tempURL("src.mov")
        let writer = try AVAssetWriter(outputURL: url, fileType: .mov)
        let input = AVAssetWriterInput(mediaType: .video, outputSettings: [
            AVVideoCodecKey: AVVideoCodecType.h264,
            AVVideoWidthKey: width,
            AVVideoHeightKey: height,
            AVVideoCompressionPropertiesKey: [AVVideoAverageBitRateKey: 8_000_000] as [String: Any],
            AVVideoColorPropertiesKey: [
                AVVideoColorPrimariesKey: AVVideoColorPrimaries_ITU_R_709_2,
                AVVideoTransferFunctionKey: AVVideoTransferFunction_ITU_R_709_2,
                AVVideoYCbCrMatrixKey: AVVideoYCbCrMatrix_ITU_R_709_2,
            ],
        ])
        input.transform = transform
        input.expectsMediaDataInRealTime = false
        let adaptor = AVAssetWriterInputPixelBufferAdaptor(assetWriterInput: input, sourcePixelBufferAttributes: [
            kCVPixelBufferPixelFormatTypeKey as String: kCVPixelFormatType_32BGRA,
            kCVPixelBufferWidthKey as String: width,
            kCVPixelBufferHeightKey as String: height,
        ])
        writer.add(input)
        guard writer.startWriting() else { throw writer.error ?? CocoaError(.fileWriteUnknown) }
        writer.startSession(atSourceTime: .zero)
        for i in 0..<frames {
            while !input.isReadyForMoreMediaData { try await Task.sleep(for: .milliseconds(2)) }
            guard let pool = adaptor.pixelBufferPool else { throw CocoaError(.fileWriteUnknown) }
            var buffer: CVPixelBuffer?
            CVPixelBufferPoolCreatePixelBuffer(kCFAllocatorDefault, pool, &buffer)
            guard let buffer else { throw CocoaError(.fileWriteUnknown) }
            let c = color(i)
            fill(buffer, left: c.left, right: c.right)
            guard adaptor.append(buffer, withPresentationTime: CMTime(value: CMTimeValue(i), timescale: fps)) else {
                throw writer.error ?? CocoaError(.fileWriteUnknown)
            }
        }
        input.markAsFinished()
        await writer.finishWriting()
        guard writer.status == .completed else { throw writer.error ?? CocoaError(.fileWriteUnknown) }
        return url
    }

    static func fill(_ buffer: CVPixelBuffer, left: RGB, right: RGB) {
        CVPixelBufferLockBaseAddress(buffer, [])
        defer { CVPixelBufferUnlockBaseAddress(buffer, []) }
        guard let base = CVPixelBufferGetBaseAddress(buffer)?.assumingMemoryBound(to: UInt8.self) else { return }
        let w = CVPixelBufferGetWidth(buffer), h = CVPixelBufferGetHeight(buffer), bpr = CVPixelBufferGetBytesPerRow(buffer)
        for y in 0..<h {
            let row = base + y * bpr
            for x in 0..<w {
                let c = x < w / 2 ? left : right
                let p = row + x * 4
                p[0] = UInt8(clamping: c.b)
                p[1] = UInt8(clamping: c.g)
                p[2] = UInt8(clamping: c.r)
                p[3] = 255
            }
        }
    }

    /// 440 Hz-es szinusz, `seconds` hosszú, sztereó CAF.
    static func makeTone(seconds: Double) throws -> URL {
        let url = tempURL("tone.caf")
        let rate = 44_100.0
        guard let format = AVAudioFormat(standardFormatWithSampleRate: rate, channels: 2) else { throw CocoaError(.fileWriteUnknown) }
        let file = try AVAudioFile(forWriting: url, settings: format.settings)
        let frames = AVAudioFrameCount(seconds * rate)
        guard let pcm = AVAudioPCMBuffer(pcmFormat: format, frameCapacity: frames), let data = pcm.floatChannelData else {
            throw CocoaError(.fileWriteUnknown)
        }
        pcm.frameLength = frames
        for i in 0..<Int(frames) {
            let v = Float(sin(2 * Double.pi * 440 * Double(i) / rate) * 0.4)
            data[0][i] = v
            data[1][i] = v
        }
        try file.write(from: pcm)
        return url
    }

    /// A kész videó kockái: kockánként a megadott pontok színe (x, y 0…1 arányban, y felülről).
    static func sample(_ url: URL, points: [(Double, Double)]) async throws -> [[RGB]] {
        let asset = AVURLAsset(url: url)
        guard let track = try await asset.loadTracks(withMediaType: .video).first else { return [] }
        let reader = try AVAssetReader(asset: asset)
        let out = AVAssetReaderTrackOutput(track: track, outputSettings: [kCVPixelBufferPixelFormatTypeKey as String: kCVPixelFormatType_32BGRA])
        reader.add(out)
        reader.startReading()
        var result: [[RGB]] = []
        while let sample = out.copyNextSampleBuffer() {
            guard let pb = CMSampleBufferGetImageBuffer(sample) else { continue }
            CVPixelBufferLockBaseAddress(pb, .readOnly)
            defer { CVPixelBufferUnlockBaseAddress(pb, .readOnly) }
            guard let base = CVPixelBufferGetBaseAddress(pb)?.assumingMemoryBound(to: UInt8.self) else { continue }
            let w = CVPixelBufferGetWidth(pb), h = CVPixelBufferGetHeight(pb), bpr = CVPixelBufferGetBytesPerRow(pb)
            result.append(points.map { pt in
                let x = min(w - 1, max(0, Int(pt.0 * Double(w)))), y = min(h - 1, max(0, Int(pt.1 * Double(h))))
                let p = base + y * bpr + x * 4
                return RGB(r: Int(p[2]), g: Int(p[1]), b: Int(p[0]))
            })
        }
        return result
    }
}
