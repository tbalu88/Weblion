import Foundation

/// Lejátszási sebesség a (levágott) forrás mentén: 1 = valós idő, 0,25 = négyszeres lassítás, 2 = kétszeres gyorsítás.
///
/// A kulcspontok között a sebesség simán vált (smoothstep a sebesség logaritmusán), így a lassítás „ívben” jön és megy,
/// nem ugrik.
public struct SpeedCurve: Codable, Sendable, Hashable {
    /// Egy pont az íven: `at` a levágott forrás hosszának hányadánál (0…1), `speed` az ottani sebesség.
    public struct Key: Codable, Sendable, Hashable {
        public var at: Double
        public var speed: Double

        public init(at: Double, speed: Double) {
            self.at = at
            self.speed = speed
        }
    }

    /// A kulcspontok `at` szerint rendezve.
    public private(set) var keys: [Key]

    public init(keys: [Key]) {
        self.keys = keys.sorted { $0.at < $1.at }
    }

    /// Végig valós idő.
    public static let normal = SpeedCurve(keys: [Key(at: 0, speed: 1)])

    /// A sebesség legkisebb és legnagyobb értéke, amit a görbe megenged.
    public static let speedRange: ClosedRange<Double> = 0.05...8

    /// A sebesség a forrás `u` pontján (0…1).
    public func speed(at u: Double) -> Double {
        guard let first = keys.first, let last = keys.last else { return 1 }
        if u <= first.at { return Self.clamped(first.speed) }
        if u >= last.at { return Self.clamped(last.speed) }
        for (a, b) in zip(keys, keys.dropFirst()) where u <= b.at {
            let span = b.at - a.at
            guard span > 0 else { return Self.clamped(b.speed) }
            let t = (u - a.at) / span
            let eased = t * t * (3 - 2 * t)
            let la = log(Self.clamped(a.speed)), lb = log(Self.clamped(b.speed))
            return exp(la + (lb - la) * eased)
        }
        return Self.clamped(last.speed)
    }

    /// A görbe leglassabb pontja (a „sima lassítás” ellenőrzéséhez).
    public var slowest: Double {
        keys.map { Self.clamped($0.speed) }.min() ?? 1
    }

    static func clamped(_ v: Double) -> Double {
        guard v.isFinite else { return 1 }
        return min(max(v, speedRange.lowerBound), speedRange.upperBound)
    }
}

/// A felületen választható sebesség-ívek. A lassítás mértékét (`slow`) külön állítjuk.
public enum SpeedStyle: String, Codable, Sendable, CaseIterable, Identifiable {
    /// Végig valós idő.
    case normal
    /// Végig lassított.
    case slow
    /// Normál – közepén lassít – normál (a klasszikus 360-as ív).
    case fastSlowFast
    /// Lassú – közepén gyors – lassú.
    case slowFastSlow
    /// Normál indulás, a végén lassul.
    case fastThenSlow

    public var id: String { rawValue }

    public var title: String {
        switch self {
        case .normal: "Normál sebesség"
        case .slow: "Végig lassított"
        case .fastSlowFast: "Gyors – lassú – gyors"
        case .slowFastSlow: "Lassú – gyors – lassú"
        case .fastThenSlow: "Gyors, a végén lassú"
        }
    }

    /// A stílus görbéje a megadott lassítással (pl. 0,25 = négyszeres lassítás).
    public func curve(slow: Double) -> SpeedCurve {
        let s = SpeedCurve.clamped(slow)
        typealias K = SpeedCurve.Key
        switch self {
        case .normal:
            return .normal
        case .slow:
            return SpeedCurve(keys: [K(at: 0, speed: s)])
        case .fastSlowFast:
            return SpeedCurve(keys: [K(at: 0, speed: 1), K(at: 0.22, speed: 1), K(at: 0.38, speed: s),
                                     K(at: 0.62, speed: s), K(at: 0.78, speed: 1), K(at: 1, speed: 1)])
        case .slowFastSlow:
            return SpeedCurve(keys: [K(at: 0, speed: s), K(at: 0.25, speed: s), K(at: 0.4, speed: 1),
                                     K(at: 0.6, speed: 1), K(at: 0.75, speed: s), K(at: 1, speed: s)])
        case .fastThenSlow:
            return SpeedCurve(keys: [K(at: 0, speed: 1), K(at: 0.45, speed: 1), K(at: 0.65, speed: s), K(at: 1, speed: s)])
        }
    }
}
