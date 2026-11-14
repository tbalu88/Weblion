import CoreGraphics
import Foundation
import SpinKit

/// Mit kérünk a renderelőtől: a felvételből kész, megosztható videót.
nonisolated struct RenderRequest: Sendable {
    /// A nyers felvétel (.mov).
    var source: URL
    /// A kész videó (.mp4) – felülírjuk.
    var output: URL
    /// Borítókép (.jpg) a galériának.
    var poster: URL
    /// Effektek, kimeneti fps, keret, felirat, zene.
    var settings: SpinSettings
    /// A keret PNG-je (ha van).
    var frameImage: URL?
    /// A zene fájlja (ha van).
    var music: URL?
    /// Kimeneti méret (álló). A tesztek kisebbet kérnek.
    var outputWidth = 1080
    var outputHeight = 1920

    /// Bitráta: 1080×1920-on 30 fps-nél 10, 60 fps-nél 14 Mbit/s; kisebb méretnél arányosan kevesebb.
    var videoBitRate: Int {
        let base = settings.outputFPS >= 60 ? 14_000_000.0 : 10_000_000.0
        let scale = Double(outputWidth * outputHeight) / (1080.0 * 1920.0)
        return max(1_000_000, Int(base * scale))
    }
}

/// A renderelés eredménye.
nonisolated struct RenderResult: Sendable {
    var duration: Double
    var frames: Int
    var bytes: Int64
}

/// Hibák a felhasználónak (magyarul).
nonisolated enum RenderError: LocalizedError {
    case noVideoTrack
    case emptyPlan
    case noFrames
    case reader(String)
    case writer(String)
    case music(String)

    var errorDescription: String? {
        switch self {
        case .noVideoTrack: "A felvételben nincs videó."
        case .emptyPlan: "A felvétel túl rövid (vagy a levágás túl nagy)."
        case .noFrames: "A felvételből nem jött kép."
        case .reader(let m): "A felvétel nem olvasható. \(m)"
        case .writer(let m): "A videó nem írható. \(m)"
        case .music(let m): "A zene nem használható. \(m)"
        }
    }
}
