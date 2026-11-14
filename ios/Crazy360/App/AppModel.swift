import AudioToolbox
import AVFoundation
import Network
import Observation
import SpinKit
import UIKit

/// Az app állapota: beállítások, rendezvény, videók, felvétel → feldolgozás → eredmény, feltöltési sor.
@Observable
final class AppModel {
    /// Hol tart a felvétel.
    enum Phase: Equatable {
        case idle
        case countdown(Int)
        case recording(started: Date, seconds: Double)
        case processing(Double)
        case result(String)
    }

    // MARK: mentett állapot

    var settings: SpinSettings {
        didSet {
            guard settings != oldValue else { return }
            JSONStore.save(settings, to: "settings.json")
            if settings.lens != oldValue.lens || settings.captureFPS != oldValue.captureFPS || settings.stabilization != oldValue.stabilization {
                Task { await configureCamera() }
            }
        }
    }

    var event: GalleryEvent? {
        didSet { JSONStore.save(event, to: "event.json") }
    }

    private(set) var clips: ClipStore

    // MARK: futás közbeni állapot

    var phase: Phase = .idle
    private(set) var cameraAvailable: Bool
    private(set) var cameraDenied = false
    private(set) var cameraStatus: CaptureService.Status?
    private(set) var cameraProblem: String?
    private(set) var isOnline = true
    private(set) var hasKey: Bool
    /// 401 után: a gépkulcs hibás vagy letiltott – a feltöltés áll, amíg új kulcs nem jön.
    private(set) var keyProblem: String?
    private(set) var uploadingID: String?
    /// Rövid üzenet a kamera képernyőjén (pl. hiba).
    var notice: String?

    let capture = CaptureService()
    private var started = false
    private var sequence: Task<Void, Never>?
    private var recordTask: Task<URL, any Error>?
    private var uploadLoop: Task<Void, Never>?
    private var monitor: NWPathMonitor?
    private let ticker = UIImpactFeedbackGenerator(style: .medium)

    init() {
        Paths.prepare()
        settings = (JSONStore.load(SpinSettings.self, from: "settings.json") ?? SpinSettings()).sanitized()
        event = JSONStore.load(GalleryEvent.self, from: "event.json")
        clips = JSONStore.load(ClipStore.self, from: "clips.json") ?? ClipStore()
        hasKey = !(Keychain.read(Keychain.boothKey) ?? "").isEmpty
        cameraAvailable = CaptureService.hasCamera
    }

    var endpoint: GalleryEndpoint { GalleryEndpoint(string: settings.serverBase) }

    // MARK: indulás

    func start() async {
        guard !started else { return }
        started = true
        UIApplication.shared.isIdleTimerDisabled = true
        try? AVAudioSession.sharedInstance().setCategory(.playback, mode: .moviePlayback, options: [.mixWithOthers])
        clips.resetInterrupted()
        saveClips()
        startNetworkMonitor()
        startUploads()
        await setUpCamera()
    }

    func setUpCamera() async {
        guard CaptureService.hasCamera else {
            cameraAvailable = false
            return
        }
        guard await CaptureService.authorize() else {
            cameraAvailable = false
            cameraDenied = true
            return
        }
        cameraAvailable = true
        cameraDenied = false
        await configureCamera()
        await capture.start()
    }

    func configureCamera() async {
        guard cameraAvailable else { return }
        let config = CaptureService.Config(lens: settings.lens, fps: settings.captureFPS, stabilization: settings.stabilization)
        do {
            cameraStatus = try await capture.configure(config)
            cameraProblem = nil
        } catch {
            cameraProblem = error.localizedDescription
        }
    }

    // MARK: felvétel

    /// Képernyő-gomb, hangerőgomb, Bluetooth-exponáló: indít, visszaszámlálás alatt megszakít, felvétel alatt leállít.
    func trigger() {
        switch phase {
        case .idle, .result:
            startSequence()
        case .countdown:
            sequence?.cancel()
        case .recording:
            recordTask?.cancel()
        case .processing:
            break
        }
    }

    func closeResult() {
        if case .result = phase { phase = .idle }
    }

    private func startSequence() {
        guard sequence == nil else { return }
        notice = nil
        if event == nil {
            event = .standalone(date: DayString.from(Date()))
            notice = "Rendezvény nélkül rögzítünk. A beállításokban választhatsz rendezvényt."
        }
        sequence = Task { [weak self] in
            await self?.runSequence()
            self?.sequence = nil
        }
    }

    private func runSequence() async {
        do {
            for n in stride(from: settings.countdown, to: 0, by: -1) {
                phase = .countdown(n)
                ticker.impactOccurred()
                if settings.countdownSound { AudioServicesPlaySystemSound(1103) }
                try await Task.sleep(for: .seconds(1))
            }
            let id = Token.make(12)
            let raw = Paths.recordings.appendingPathComponent("\(id).mov")
            let seconds = settings.recordSeconds
            phase = .recording(started: Date(), seconds: seconds)
            if settings.countdownSound { AudioServicesPlaySystemSound(1113) }
            let source: URL
            if cameraAvailable {
                let capture = self.capture
                let task = Task { try await capture.record(to: raw, seconds: seconds) }
                recordTask = task
                source = try await task.value
                recordTask = nil
            } else {
                source = try await DemoRecording.make(to: raw, seconds: min(seconds, 6))
            }
            if settings.countdownSound { AudioServicesPlaySystemSound(1114) }
            phase = .processing(0)
            let clip = try await process(id: id, source: source)
            phase = .result(clip.id)
        } catch is CancellationError {
            phase = .idle
        } catch {
            phase = .idle
            notice = error.localizedDescription
        }
    }

    private func process(id: String, source: URL) async throws -> Clip {
        let video = Paths.video(id), poster = Paths.poster(id)
        let request = RenderRequest(source: source, output: video, poster: poster, settings: settings,
                                    frameImage: frameURL, music: musicURL)
        let model = self
        let result = try await VideoRenderer().render(request) { p in
            Task { @MainActor in model.updateProgress(p) }
        }
        // a nyers felvétel csak sikeres feldolgozás után megy (hiba esetén megmarad a Felvételek mappában)
        try? FileManager.default.removeItem(at: source)
        let ev = event ?? .standalone(date: DayString.from(Date()))
        let clip = Clip(id: id, ev: ev.ev, deal: ev.deal, date: ev.date, eventTitle: ev.title, video: video.lastPathComponent,
                        poster: FileManager.default.fileExists(atPath: poster.path) ? poster.lastPathComponent : nil,
                        createdAt: Date(), duration: result.duration, bytes: result.bytes)
        clips.add(clip)
        saveClips()
        if settings.saveToPhotos {
            Task {
                let saved = await PhotoSaver.save(video)
                if !saved { model.notice = "A Fotókba mentés nem sikerült (engedély?)." }
            }
        }
        return clip
    }

    private func updateProgress(_ p: Double) {
        if case .processing = phase { phase = .processing(p) }
    }

    // MARK: keret, zene

    var frameURL: URL? { settings.overlayFile.map { Paths.frames.appendingPathComponent($0) } }
    var musicURL: URL? { settings.musicFile.map { Paths.music.appendingPathComponent($0) } }

    /// Keret átvétele (Fájlok / Fotók): PNG a Keretek mappába.
    func importFrame(data: Data, name: String) throws {
        let file = Self.safeName(name, fallbackExtension: "png")
        try data.write(to: Paths.frames.appendingPathComponent(file), options: .atomic)
        settings.overlayFile = file
    }

    func importFrame(from url: URL) throws {
        let data = try Self.readScoped(url)
        try importFrame(data: data, name: url.lastPathComponent)
    }

    /// Zene átvétele a Fájlokból a Zenék mappába.
    func importMusic(from url: URL) throws {
        let data = try Self.readScoped(url)
        let file = Self.safeName(url.lastPathComponent, fallbackExtension: "m4a")
        try data.write(to: Paths.music.appendingPathComponent(file), options: .atomic)
        settings.musicFile = file
        settings.musicTitle = url.deletingPathExtension().lastPathComponent
        settings.musicStart = 0
    }

    private static func readScoped(_ url: URL) throws -> Data {
        let scoped = url.startAccessingSecurityScopedResource()
        defer { if scoped { url.stopAccessingSecurityScopedResource() } }
        return try Data(contentsOf: url)
    }

    /// Fájlnév egyedi előtaggal, csak biztonságos karakterekkel.
    private static func safeName(_ name: String, fallbackExtension: String) -> String {
        let base = (name as NSString).deletingPathExtension
        let ext = (name as NSString).pathExtension.lowercased()
        let cleaned = base.unicodeScalars.map { CharacterSet.alphanumerics.contains($0) || $0 == "-" ? String($0) : "-" }.joined()
        return Token.make(6) + "-" + String(cleaned.prefix(40)) + "." + (ext.isEmpty ? fallbackExtension : ext)
    }

    // MARK: rendezvény, gépkulcs

    func events(on day: Date) async throws -> [GalleryEvent] {
        guard let key = Keychain.read(Keychain.boothKey), !key.isEmpty else {
            throw GalleryClient.Failure.badKey("Előbb add meg a gépkulcsot (Beállítások → Gépkulcs).")
        }
        return try await GalleryClient(endpoint: endpoint, key: key).events(date: DayString.from(day))
    }

    /// Párosítás: a kulcsot a rendezvénylista lekérésével ellenőrizzük, csak jó kulcs kerül a Kulcskarikába.
    func pair(key raw: String) async throws {
        let key = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        guard key.count >= 20 else { throw GalleryClient.Failure.badKey("Túl rövid – a teljes gépkulcsot másold be.") }
        _ = try await GalleryClient(endpoint: endpoint, key: key).events(date: DayString.from(Date()))
        Keychain.write(key, account: Keychain.boothKey)
        hasKey = true
        keyProblem = nil
        clips.wakeAll()
        saveClips()
    }

    func forgetKey() {
        Keychain.delete(Keychain.boothKey)
        hasKey = false
    }

    // MARK: videók

    func retry(_ id: String) {
        clips.retryNow(id: id)
        saveClips()
    }

    func delete(_ id: String) {
        if case .result(id) = phase { phase = .idle }
        try? FileManager.default.removeItem(at: Paths.video(id))
        try? FileManager.default.removeItem(at: Paths.poster(id))
        clips.remove(id: id)
        saveClips()
    }

    func videoURL(_ clip: Clip) -> URL { Paths.videos.appendingPathComponent(clip.video) }
    func posterURL(_ clip: Clip) -> URL? { clip.poster.map { Paths.videos.appendingPathComponent($0) } }
    func shareURL(_ clip: Clip) -> URL { endpoint.clipURL(ev: clip.ev, photo: clip.id) }

    private func saveClips() {
        JSONStore.save(clips, to: "clips.json")
    }

    // MARK: feltöltés

    private func startUploads() {
        uploadLoop = Task { [weak self] in
            while !Task.isCancelled {
                guard let self else { return }
                await self.uploadNext()
                try? await Task.sleep(for: .seconds(2))
            }
        }
    }

    private func uploadNext() async {
        guard uploadingID == nil, isOnline, keyProblem == nil,
              let key = Keychain.read(Keychain.boothKey), !key.isEmpty,
              let clip = clips.nextToUpload(now: Date())
        else { return }
        let video = videoURL(clip)
        guard FileManager.default.fileExists(atPath: video.path) else {
            clips.markStopped(id: clip.id, message: "A videófájl hiányzik a telefonról.")
            saveClips()
            return
        }
        uploadingID = clip.id
        clips.markUploading(id: clip.id)
        do {
            let url = try await GalleryClient(endpoint: endpoint, key: key).upload(clip, video: video, poster: posterURL(clip))
            clips.markDone(id: clip.id, url: url, at: Date())
        } catch let failure as GalleryClient.Failure {
            let message = failure.errorDescription ?? ""
            switch failure {
            case .badKey:
                keyProblem = message
                clips.markFailed(id: clip.id, message: message, now: Date())
            case .offline, .server:
                clips.markFailed(id: clip.id, message: message, now: Date())
            case .rejected:
                clips.markStopped(id: clip.id, message: message)
            }
        } catch {
            clips.markFailed(id: clip.id, message: error.localizedDescription, now: Date())
        }
        uploadingID = nil
        saveClips()
    }

    private func startNetworkMonitor() {
        let model = self
        monitor = Self.makeMonitor { online in
            Task { @MainActor in model.networkChanged(online) }
        }
    }

    private func networkChanged(_ online: Bool) {
        let cameBack = online && !isOnline
        isOnline = online
        if cameBack {
            clips.wakeAll()
            saveClips()
        }
    }

    /// A figyelő a saját szálán hív – a kezelője ezért nem a fő szálhoz kötött.
    nonisolated private static func makeMonitor(_ onChange: @escaping @Sendable (Bool) -> Void) -> NWPathMonitor {
        let monitor = NWPathMonitor()
        monitor.pathUpdateHandler = { path in onChange(path.status == .satisfied) }
        monitor.start(queue: DispatchQueue(label: "hu.eventcrazy.crazy360.net"))
        return monitor
    }
}
