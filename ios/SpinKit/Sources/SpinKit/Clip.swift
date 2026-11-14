import Foundation

/// Egy elkészült 360-as videó a telefonon, a galériába töltés állapotával.
public struct Clip: Codable, Sendable, Hashable, Identifiable {
    public enum Upload: Codable, Sendable, Hashable {
        /// Sorban vár (vagy hiba után újrapróbálásra vár).
        case waiting
        /// Épp megy fel.
        case uploading
        /// Fent van a galériában.
        case done(url: String, at: Date)
        /// Nem próbáljuk újra magától (pl. túl nagy fájl) – kézzel újraindítható.
        case stopped(message: String)
    }

    /// Kép-azonosító a galériában (egyben a fájlnév alapja).
    public var id: String
    public var ev: String
    public var deal: Int
    public var date: String
    public var eventTitle: String
    /// Fájlnevek a videók mappájában.
    public var video: String
    public var poster: String?
    public var createdAt: Date
    public var duration: Double
    public var bytes: Int64
    public var upload: Upload
    public var attempts: Int
    /// Legközelebb ekkor próbáljuk (hiba után).
    public var retryAt: Date?
    /// Az utolsó hiba szövege (a felületen).
    public var lastError: String?

    public init(id: String, ev: String, deal: Int, date: String, eventTitle: String, video: String, poster: String?,
                createdAt: Date, duration: Double, bytes: Int64) {
        self.id = id
        self.ev = ev
        self.deal = deal
        self.date = date
        self.eventTitle = eventTitle
        self.video = video
        self.poster = poster
        self.createdAt = createdAt
        self.duration = duration
        self.bytes = bytes
        upload = .waiting
        attempts = 0
        retryAt = nil
        lastError = nil
    }

    public var isUploaded: Bool {
        if case .done = upload { return true }
        return false
    }
}

/// A videók listája és a feltöltési sor (tiszta logika; a mentést és a hálózatot az app végzi).
public struct ClipStore: Codable, Sendable, Equatable {
    /// Legújabb elöl.
    public private(set) var clips: [Clip]

    public init(clips: [Clip] = []) {
        self.clips = clips
    }

    public subscript(id: String) -> Clip? {
        clips.first { $0.id == id }
    }

    public mutating func add(_ clip: Clip) {
        clips.removeAll { $0.id == clip.id }
        clips.insert(clip, at: 0)
    }

    public mutating func remove(id: String) {
        clips.removeAll { $0.id == id }
    }

    /// Ennyi videó vár feltöltésre (a megállítottak nélkül).
    public var pendingCount: Int {
        clips.filter {
            switch $0.upload {
            case .waiting, .uploading: true
            default: false
            }
        }.count
    }

    /// A következő feltöltendő: a legrégebbi várakozó, amelynek lejárt a várakozási ideje.
    public func nextToUpload(now: Date) -> Clip? {
        clips.reversed().first { c in
            guard case .waiting = c.upload else { return false }
            return (c.retryAt ?? .distantPast) <= now
        }
    }

    /// A legkorábbi időpont, amikor újra próbálkozni kell (nil, ha nincs ilyen).
    public func nextRetryDate() -> Date? {
        clips.compactMap { c -> Date? in
            guard case .waiting = c.upload else { return nil }
            return c.retryAt
        }.min()
    }

    public mutating func markUploading(id: String) {
        update(id) { $0.upload = .uploading }
    }

    public mutating func markDone(id: String, url: String, at date: Date) {
        update(id) {
            $0.upload = .done(url: url, at: date)
            $0.retryAt = nil
            $0.lastError = nil
        }
    }

    /// Átmeneti hiba (nincs net, szerverhiba): később újra, egyre ritkábban.
    public mutating func markFailed(id: String, message: String, now: Date) {
        update(id) {
            $0.attempts += 1
            $0.upload = .waiting
            $0.lastError = message
            $0.retryAt = now.addingTimeInterval(Self.backoff(attempt: $0.attempts))
        }
    }

    /// Végleges hiba (pl. túl nagy fájl): nem próbáljuk újra magától.
    public mutating func markStopped(id: String, message: String) {
        update(id) {
            $0.attempts += 1
            $0.upload = .stopped(message: message)
            $0.lastError = message
            $0.retryAt = nil
        }
    }

    /// Kézi újraindítás (vagy új gépkulcs után): azonnal sorra kerül.
    public mutating func retryNow(id: String) {
        update(id) {
            if case .done = $0.upload { return }
            $0.upload = .waiting
            $0.retryAt = nil
        }
    }

    /// Minden várakozó azonnal mehet (pl. visszajött a net).
    public mutating func wakeAll() {
        for i in clips.indices {
            if case .waiting = clips[i].upload { clips[i].retryAt = nil }
        }
    }

    /// Induláskor: ami „feltöltés közben” maradt (az app leállt), újra vár.
    public mutating func resetInterrupted() {
        for i in clips.indices {
            if case .uploading = clips[i].upload { clips[i].upload = .waiting }
        }
    }

    /// 5 mp, 15 mp, 30 mp, 1 perc, 2 perc, majd 5 percenként.
    public static func backoff(attempt: Int) -> TimeInterval {
        let steps: [TimeInterval] = [5, 15, 30, 60, 120, 300]
        return steps[min(max(attempt, 1), steps.count) - 1]
    }

    private mutating func update(_ id: String, _ change: (inout Clip) -> Void) {
        guard let i = clips.firstIndex(where: { $0.id == id }) else { return }
        change(&clips[i])
    }
}
