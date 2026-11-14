/// Folyamatos kockaválasztó: a forrás kockái időrendben érkeznek (dekódolás közben), és minden kért kimeneti
/// időponthoz a hozzá legközelebbi forráskockát rendeli. Egyszerre csak az előző kockát kell megtartani.
///
/// Használat: minden új forráskockánál `feed(t)` megmondja, mely kimeneti indexeket kell az **előző** kockával
/// kiszolgálni; a végén `finish()` az utolsó kockáé.
public struct NearestFramePicker: Sendable {
    private let targets: [Double]
    private var next = 0
    private var previous: Double?

    /// - Parameter targets: a kért forrásidők, nem csökkenő sorrendben.
    public init<S: Sequence>(targets: S) where S.Element == Double {
        self.targets = Array(targets)
    }

    /// Minden kért időpont kiszolgálva.
    public var isDone: Bool { next >= targets.count }

    /// Új forráskocka érkezett `time` időponttal (szigorúan növekvő sorrendben).
    /// - Returns: az előző kockával kiszolgálandó kimeneti indexek.
    public mutating func feed(_ time: Double) -> Range<Int> {
        defer { previous = time }
        guard let prev = previous else { return next..<next }
        let mid = (prev + time) / 2
        let start = next
        while next < targets.count && targets[next] <= mid { next += 1 }
        return start..<next
    }

    /// Nincs több forráskocka: a maradék időpontok az utolsó kockát kapják.
    public mutating func finish() -> Range<Int> {
        guard previous != nil else { return next..<next }
        let start = next
        next = targets.count
        return start..<next
    }
}
