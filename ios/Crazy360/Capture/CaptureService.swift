@preconcurrency import AVFoundation
import Foundation
import SpinKit

/// A kamera: a felvétel-munkamenet egy saját soros szálon (az actor végrehajtója), mert a startRunning() blokkol.
actor CaptureService {
    /// Mit kértünk.
    nonisolated struct Config: Sendable, Equatable {
        var lens: Lens
        var fps: Int
        var stabilization: Bool
    }

    /// Mit kaptunk (a felületen kiírjuk).
    nonisolated struct Status: Sendable, Equatable {
        var lens: Lens
        var fps: Int
        var width: Int
        var height: Int
        var binned: Bool
        var stabilized: Bool

        var summary: String {
            "\(lens == .ultraWide ? "0,5×" : "1×") · \(fps) fps · \(min(width, height))p\(stabilized ? " · stabil" : "")"
        }
    }

    nonisolated enum CaptureError: LocalizedError {
        case noCamera
        case notAuthorized
        case configuration(String)
        case busy

        var errorDescription: String? {
            switch self {
            case .noCamera: "Ezen az eszközön nincs használható hátsó kamera."
            case .notAuthorized: "Nincs engedély a kamerához. Beállítások → Crazy 360 → Kamera."
            case .configuration(let m): "A kamera nem állítható be. \(m)"
            case .busy: "Már folyik egy felvétel."
            }
        }
    }

    /// A munkamenet. Az előnézeti réteg a fő szálon kapcsolódik hozzá (az AVCaptureSession ezt kezeli),
    /// minden más csak ezen az actoron fut – ezért `nonisolated(unsafe)`.
    nonisolated(unsafe) let session = AVCaptureSession()

    private let queue = DispatchSerialQueue(label: "hu.eventcrazy.crazy360.capture")
    nonisolated var unownedExecutor: UnownedSerialExecutor { queue.asUnownedSerialExecutor() }

    private let movieOutput = AVCaptureMovieFileOutput()
    private var input: AVCaptureDeviceInput?
    private var recording: RecordingDelegate?

    /// Kamera-engedély (szükség esetén megkérdezi).
    nonisolated static func authorize() async -> Bool {
        switch AVCaptureDevice.authorizationStatus(for: .video) {
        case .authorized: return true
        case .notDetermined: return await AVCaptureDevice.requestAccess(for: .video)
        default: return false
        }
    }

    /// Van-e egyáltalán hátsó kamera (a szimulátoron nincs).
    nonisolated static var hasCamera: Bool {
        AVCaptureDevice.default(.builtInWideAngleCamera, for: .video, position: .back) != nil
    }

    func configure(_ config: Config) throws -> Status {
        guard !movieOutput.isRecording else { throw CaptureError.busy }
        let preferred: AVCaptureDevice.DeviceType = config.lens == .ultraWide ? .builtInUltraWideCamera : .builtInWideAngleCamera
        guard let device = AVCaptureDevice.default(preferred, for: .video, position: .back)
            ?? AVCaptureDevice.default(.builtInWideAngleCamera, for: .video, position: .back)
        else { throw CaptureError.noCamera }
        let lens: Lens = device.deviceType == .builtInUltraWideCamera ? .ultraWide : .wide

        session.beginConfiguration()
        if let input { session.removeInput(input) }
        let newInput: AVCaptureDeviceInput
        do {
            newInput = try AVCaptureDeviceInput(device: device)
        } catch {
            session.commitConfiguration()
            throw CaptureError.configuration(error.localizedDescription)
        }
        guard session.canAddInput(newInput) else {
            session.commitConfiguration()
            throw CaptureError.configuration("bemenet")
        }
        session.addInput(newInput)
        input = newInput
        if !session.outputs.contains(movieOutput) {
            guard session.canAddOutput(movieOutput) else {
                session.commitConfiguration()
                throw CaptureError.configuration("kimenet")
            }
            session.addOutput(movieOutput)
        }
        session.commitConfiguration()

        // formátum: a kért fps-hez a legjobb 8 bites 1080p
        let formats = device.formats
        let infos = formats.map { f -> CameraFormatInfo in
            let d = CMVideoFormatDescriptionGetDimensions(f.formatDescription)
            let sub = CMFormatDescriptionGetMediaSubType(f.formatDescription)
            let eightBit = sub == kCVPixelFormatType_420YpCbCr8BiPlanarVideoRange || sub == kCVPixelFormatType_420YpCbCr8BiPlanarFullRange
            let maxFPS = f.videoSupportedFrameRateRanges.map(\.maxFrameRate).max() ?? 30
            return CameraFormatInfo(width: Int(d.width), height: Int(d.height), maxFPS: maxFPS, binned: f.isVideoBinned, eightBit: eightBit)
        }
        guard let best = FormatChooser.best(infos, fps: config.fps) else { throw CaptureError.configuration("nincs formátum") }
        let format = formats[best]
        let fps = Self.supportedFPS(Double(config.fps), in: format.videoSupportedFrameRateRanges)
        do {
            try device.lockForConfiguration()
            device.activeFormat = format
            let frame = CMTime(value: 1, timescale: CMTimeScale(max(fps, 1)))
            device.activeVideoMinFrameDuration = frame
            device.activeVideoMaxFrameDuration = frame
            if format.isVideoHDRSupported {
                device.automaticallyAdjustsVideoHDREnabled = false
                device.isVideoHDREnabled = false
            }
            if format.supportedColorSpaces.contains(.sRGB) { device.activeColorSpace = .sRGB }
            if device.isFocusModeSupported(.continuousAutoFocus) { device.focusMode = .continuousAutoFocus }
            if device.isExposureModeSupported(.continuousAutoExposure) { device.exposureMode = .continuousAutoExposure }
            if device.isWhiteBalanceModeSupported(.continuousAutoWhiteBalance) { device.whiteBalanceMode = .continuousAutoWhiteBalance }
            device.unlockForConfiguration()
        } catch {
            throw CaptureError.configuration(error.localizedDescription)
        }

        // álló kép, stabilizálás, HEVC (kisebb fájl, ugyanaz a minőség)
        var stabilized = false
        if let connection = movieOutput.connection(with: .video) {
            if connection.isVideoRotationAngleSupported(90) { connection.videoRotationAngle = 90 }
            if config.stabilization, connection.isVideoStabilizationSupported {
                let modes: [AVCaptureVideoStabilizationMode] = [.cinematicExtended, .cinematic, .standard]
                if let mode = modes.first(where: { format.isVideoStabilizationModeSupported($0) }) {
                    connection.preferredVideoStabilizationMode = mode
                    stabilized = true
                }
            } else {
                connection.preferredVideoStabilizationMode = .off
            }
            if movieOutput.availableVideoCodecTypes.contains(.hevc) {
                movieOutput.setOutputSettings([AVVideoCodecKey: AVVideoCodecType.hevc], for: connection)
            }
        }
        let dims = CMVideoFormatDescriptionGetDimensions(format.formatDescription)
        return Status(lens: lens, fps: fps, width: Int(dims.width), height: Int(dims.height), binned: format.isVideoBinned, stabilized: stabilized)
    }

    /// A kért fps a formátum valamelyik tartományán belül (különben az AVFoundation kivétellel leállítja az appot).
    nonisolated static func supportedFPS(_ wanted: Double, in ranges: [AVFrameRateRange]) -> Int {
        if let r = ranges.first(where: { $0.minFrameRate <= wanted && wanted <= $0.maxFrameRate }) {
            return Int(min(wanted, r.maxFrameRate).rounded(.down))
        }
        // nincs ilyen tartomány: a legközelebbi határ (lefelé kerekítve, a tartományon belül)
        let bounds = ranges.flatMap { [$0.minFrameRate, $0.maxFrameRate] }
        guard let nearest = bounds.min(by: { abs($0 - wanted) < abs($1 - wanted) }) else { return 30 }
        let range = ranges.first { $0.minFrameRate <= nearest && nearest <= $0.maxFrameRate }
        let value = Int(nearest.rounded(.down))
        if let range, Double(value) < range.minFrameRate { return Int(range.minFrameRate.rounded(.up)) }
        return max(1, value)
    }

    func start() {
        if !session.isRunning { session.startRunning() }
    }

    func stop() {
        if session.isRunning { session.stopRunning() }
    }

    /// Felvétel `seconds` hosszan. Ha a hívó feladatát megszakítják, a felvétel ott áll le (a fájl akkor is jó).
    func record(to url: URL, seconds: Double) async throws -> URL {
        guard !movieOutput.isRecording else { throw CaptureError.busy }
        let delegate = RecordingDelegate()
        recording = delegate
        defer { recording = nil }
        try? FileManager.default.removeItem(at: url)
        movieOutput.maxRecordedDuration = CMTime(seconds: seconds + 2, preferredTimescale: 600)
        movieOutput.startRecording(to: url, recordingDelegate: delegate)
        try? await Task.sleep(for: .seconds(seconds))
        if movieOutput.isRecording { movieOutput.stopRecording() }
        return try await delegate.finished().get()
    }

    /// Azonnali leállítás (a `record` ilyenkor is visszaadja a fájlt).
    func stopRecording() {
        if movieOutput.isRecording { movieOutput.stopRecording() }
    }
}

/// A felvétel vége (az AVFoundation tetszőleges szálon hívja).
nonisolated final class RecordingDelegate: NSObject, AVCaptureFileOutputRecordingDelegate, Sendable {
    private let stream: AsyncStream<Result<URL, any Error>>
    private let continuation: AsyncStream<Result<URL, any Error>>.Continuation

    override init() {
        let pair = AsyncStream.makeStream(of: Result<URL, any Error>.self)
        stream = pair.stream
        continuation = pair.continuation
        super.init()
    }

    func finished() async -> Result<URL, any Error> {
        for await result in stream { return result }
        return .failure(CancellationError())
    }

    func fileOutput(_ output: AVCaptureFileOutput, didFinishRecordingTo outputFileURL: URL,
                    from connections: [AVCaptureConnection], error: (any Error)?) {
        // „elérte a maximális hosszt” és társai: a fájl ilyenkor is jó
        if let error, (error as NSError).userInfo[AVErrorRecordingSuccessfullyFinishedKey] as? Bool != true {
            continuation.yield(.failure(error))
        } else {
            continuation.yield(.success(outputFileURL))
        }
        continuation.finish()
    }
}
