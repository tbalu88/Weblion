import Foundation

/// Melyik hátsó kamera.
public enum Lens: String, Codable, Sendable, CaseIterable, Identifiable {
    /// 0,5× nagylátószög (a 360-hoz ez az alap: a csoport is belefér).
    case ultraWide
    /// 1× fő kamera (a 240 fps-t ez tudja a legtöbb iPhone-on).
    case wide

    public var id: String { rawValue }
    public var title: String {
        switch self {
        case .ultraWide: "0,5× nagylátószög"
        case .wide: "1× fő kamera"
        }
    }
}

/// A felirat színe.
public enum CaptionColor: String, Codable, Sendable, CaseIterable, Identifiable {
    case white, black, gold

    public var id: String { rawValue }
    public var title: String {
        switch self {
        case .white: "Fehér"
        case .black: "Fekete"
        case .gold: "Arany"
        }
    }
    /// RGB 0…1
    public var rgb: (r: Double, g: Double, b: Double) {
        switch self {
        case .white: (r: 1, g: 1, b: 1)
        case .black: (r: 0.05, g: 0.05, b: 0.07)
        case .gold: (r: 0.95, g: 0.76, b: 0.40)
        }
    }
}

/// Az app beállításai (JSON-ban mentve). Minden mező alapértékkel: egy régebbi vagy hiányos mentés is betölthető.
public struct SpinSettings: Codable, Sendable, Equatable {
    // Felvétel
    public var lens: Lens = .ultraWide
    public var captureFPS: Int = 120
    public var recordSeconds: Double = 8
    public var countdown: Int = 3
    public var stabilization: Bool = true

    // Effektek
    public var speedStyle: SpeedStyle = .fastSlowFast
    public var slowFactor: Double = 0.25
    public var boomerang: Bool = true
    public var trimStart: Double = 0.3
    public var trimEnd: Double = 0.3

    // Kimenet
    public var outputFPS: Int = 30

    // Keret és felirat
    public var overlayFile: String?
    public var captionTop: String = ""
    public var captionBottom: String = ""
    public var captionColor: CaptionColor = .white

    // Zene
    public var musicFile: String?
    public var musicTitle: String = ""
    public var musicStart: Double = 0
    public var musicVolume: Double = 0.9

    // Egyéb
    public var saveToPhotos: Bool = false
    public var serverBase: String = GalleryEndpoint.defaultBase

    public init() {}

    // A választható értékek (a felülethez és az ellenőrzéshez).
    public static let captureFPSChoices = [60, 120, 240]
    public static let outputFPSChoices = [30, 60]
    public static let countdownChoices = [0, 3, 5, 10]
    public static let slowChoices: [Double] = [0.5, 1.0 / 3.0, 0.25, 0.125]
    public static let recordRange: ClosedRange<Double> = 3...30
    public static let trimRange: ClosedRange<Double> = 0...3

    /// A kész videó sebesség-íve.
    public var curve: SpeedCurve { speedStyle.curve(slow: slowFactor) }

    /// A kész videó várható hossza (mp).
    public var estimatedOutputSeconds: Double {
        FramePlan.estimatedDuration(recordSeconds: recordSeconds, trimStart: trimStart, trimEnd: trimEnd,
                                    curve: curve, boomerang: boomerang)
    }

    /// Értékek a megengedett tartományba (sérült vagy kézzel szerkesztett mentés után).
    public func sanitized() -> SpinSettings {
        var s = self
        if !Self.captureFPSChoices.contains(s.captureFPS) { s.captureFPS = 120 }
        if !Self.outputFPSChoices.contains(s.outputFPS) { s.outputFPS = 30 }
        if !Self.countdownChoices.contains(s.countdown) { s.countdown = 3 }
        s.recordSeconds = Self.clamp(s.recordSeconds, Self.recordRange, fallback: 8)
        s.trimStart = Self.clamp(s.trimStart, Self.trimRange, fallback: 0.3)
        s.trimEnd = Self.clamp(s.trimEnd, Self.trimRange, fallback: 0.3)
        s.slowFactor = Self.clamp(s.slowFactor, 0.1...1, fallback: 0.25)
        s.musicStart = Self.clamp(s.musicStart, 0...600, fallback: 0)
        s.musicVolume = Self.clamp(s.musicVolume, 0...1, fallback: 0.9)
        s.captionTop = String(s.captionTop.prefix(80))
        s.captionBottom = String(s.captionBottom.prefix(80))
        return s
    }

    private static func clamp(_ v: Double, _ r: ClosedRange<Double>, fallback: Double) -> Double {
        v.isFinite ? min(max(v, r.lowerBound), r.upperBound) : fallback
    }

    // MARK: tűrő betöltés

    enum CodingKeys: String, CodingKey {
        case lens, captureFPS, recordSeconds, countdown, stabilization
        case speedStyle, slowFactor, boomerang, trimStart, trimEnd, outputFPS
        case overlayFile, captionTop, captionBottom, captionColor
        case musicFile, musicTitle, musicStart, musicVolume
        case saveToPhotos, serverBase
    }

    public init(from decoder: any Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        let d = SpinSettings()
        func v<T: Decodable>(_ key: CodingKeys, _ fallback: T) -> T {
            (try? c.decodeIfPresent(T.self, forKey: key)) ?? fallback
        }
        lens = v(.lens, d.lens)
        captureFPS = v(.captureFPS, d.captureFPS)
        recordSeconds = v(.recordSeconds, d.recordSeconds)
        countdown = v(.countdown, d.countdown)
        stabilization = v(.stabilization, d.stabilization)
        speedStyle = v(.speedStyle, d.speedStyle)
        slowFactor = v(.slowFactor, d.slowFactor)
        boomerang = v(.boomerang, d.boomerang)
        trimStart = v(.trimStart, d.trimStart)
        trimEnd = v(.trimEnd, d.trimEnd)
        outputFPS = v(.outputFPS, d.outputFPS)
        overlayFile = try? c.decodeIfPresent(String.self, forKey: .overlayFile)
        captionTop = v(.captionTop, d.captionTop)
        captionBottom = v(.captionBottom, d.captionBottom)
        captionColor = v(.captionColor, d.captionColor)
        musicFile = try? c.decodeIfPresent(String.self, forKey: .musicFile)
        musicTitle = v(.musicTitle, d.musicTitle)
        musicStart = v(.musicStart, d.musicStart)
        musicVolume = v(.musicVolume, d.musicVolume)
        saveToPhotos = v(.saveToPhotos, d.saveToPhotos)
        serverBase = v(.serverBase, d.serverBase)
    }
}
