import AVFoundation
import CoreImage
import Foundation

/// Próbafelvétel kamera nélkül (szimulátor, bemutató): forgó színátmenet és mozgó fénypont, 60 fps.
/// Ugyanúgy fájlt ad, mint a kamera, így a teljes folyamat (feldolgozás, feltöltés, QR) kipróbálható.
nonisolated enum DemoRecording {
    @concurrent
    static func make(to url: URL, seconds: Double, fps: Int32 = 60, width: Int = 540, height: Int = 960) async throws -> URL {
        try? FileManager.default.removeItem(at: url)
        let writer = try AVAssetWriter(outputURL: url, fileType: .mov)
        let input = AVAssetWriterInput(mediaType: .video, outputSettings: [
            AVVideoCodecKey: AVVideoCodecType.h264,
            AVVideoWidthKey: width,
            AVVideoHeightKey: height,
        ])
        input.expectsMediaDataInRealTime = false
        let adaptor = AVAssetWriterInputPixelBufferAdaptor(assetWriterInput: input, sourcePixelBufferAttributes: [
            kCVPixelBufferPixelFormatTypeKey as String: kCVPixelFormatType_32BGRA,
            kCVPixelBufferWidthKey as String: width,
            kCVPixelBufferHeightKey as String: height,
            kCVPixelBufferIOSurfacePropertiesKey as String: [String: Any](),
        ])
        writer.add(input)
        guard writer.startWriting() else { throw writer.error ?? CocoaError(.fileWriteUnknown) }
        writer.startSession(atSourceTime: .zero)
        let context = CIContext(options: [.workingColorSpace: NSNull(), .outputColorSpace: NSNull()])
        let rect = CGRect(x: 0, y: 0, width: width, height: height)
        let frames = Int(seconds * Double(fps))
        for i in 0..<frames {
            try Task.checkCancellation()
            while !input.isReadyForMoreMediaData { try await Task.sleep(for: .milliseconds(2)) }
            guard let pool = adaptor.pixelBufferPool else { throw CocoaError(.fileWriteUnknown) }
            var buffer: CVPixelBuffer?
            CVPixelBufferPoolCreatePixelBuffer(kCFAllocatorDefault, pool, &buffer)
            guard let buffer else { throw CocoaError(.fileWriteUnknown) }
            let t = Double(i) / Double(fps)
            context.render(frame(at: t, rect: rect), to: buffer, bounds: rect, colorSpace: nil)
            guard adaptor.append(buffer, withPresentationTime: CMTime(value: CMTimeValue(i), timescale: fps)) else {
                throw writer.error ?? CocoaError(.fileWriteUnknown)
            }
        }
        input.markAsFinished()
        await writer.finishWriting()
        guard writer.status == .completed else { throw writer.error ?? CocoaError(.fileWriteUnknown) }
        return url
    }

    /// Egy kocka: körbeforduló kétszínű átmenet + vízszintesen „körbemenő” fénypont (mint a forgó kar képe).
    static func frame(at t: Double, rect: CGRect) -> CIImage {
        let angle = t * 1.6
        let c = CGPoint(x: rect.midX, y: rect.midY)
        let r = max(rect.width, rect.height)
        let p0 = CIVector(x: c.x + cos(angle) * r, y: c.y + sin(angle) * r)
        let p1 = CIVector(x: c.x - cos(angle) * r, y: c.y - sin(angle) * r)
        let navy = CIColor(red: 0.08, green: 0.11, blue: 0.20)
        let orange = CIColor(red: 0.91, green: 0.54, blue: 0.29)
        let gradient = CIFilter(name: "CILinearGradient", parameters: ["inputPoint0": p0, "inputPoint1": p1, "inputColor0": navy, "inputColor1": orange])?
            .outputImage ?? CIImage(color: navy)
        let x = rect.midX + sin(t * 2.4) * rect.width * 0.38
        let spot = CIFilter(name: "CIRadialGradient", parameters: [
            "inputCenter": CIVector(x: x, y: rect.height * 0.55),
            "inputRadius0": rect.width * 0.06,
            "inputRadius1": rect.width * 0.2,
            "inputColor0": CIColor(red: 1, green: 1, blue: 1, alpha: 0.95),
            "inputColor1": CIColor(red: 1, green: 1, blue: 1, alpha: 0),
        ])?.outputImage
        let image = spot.map { $0.composited(over: gradient) } ?? gradient
        return image.cropped(to: rect)
    }
}
