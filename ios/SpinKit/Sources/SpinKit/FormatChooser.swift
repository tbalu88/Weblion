/// Egy kameraformátum adatai (a telefon `AVCaptureDevice.Format`-jából, fekvő méretben).
public struct CameraFormatInfo: Sendable, Equatable {
    public var width: Int
    public var height: Int
    public var maxFPS: Double
    /// Pixel-összevonásos (binned) formátum: nagy fps-hez kell, de kicsit lágyabb kép.
    public var binned: Bool
    /// 8 bites (SDR) képformátum. A 10 bites HDR-t kerüljük: a weben kifakult lenne.
    public var eightBit: Bool

    public init(width: Int, height: Int, maxFPS: Double, binned: Bool, eightBit: Bool) {
        self.width = width
        self.height = height
        self.maxFPS = maxFPS
        self.binned = binned
        self.eightBit = eightBit
    }
}

/// A felvételhez legjobb formátum: a kért fps-t tudja, 1080p, 8 bites, lehetőleg nem binned.
/// Ha a kért fps-t egyik sem tudja, a legnagyobb elérhető fps-ű 1080p-s (a felület kiírja, mit kaptunk).
public enum FormatChooser {
    public static func best(_ formats: [CameraFormatInfo], fps: Int, preferredHeight: Int = 1080) -> Int? {
        let pool = formats.indices.filter { formats[$0].eightBit }
        let candidates = pool.isEmpty ? Array(formats.indices) : pool
        guard !candidates.isEmpty else { return nil }
        let want = Double(fps) - 0.5
        func key(_ i: Int) -> (Int, Int, Double, Double) {
            let f = formats[i]
            let supports = f.maxFPS >= want
            // pontosan 1080 a legjobb, a nagyobb (4K) még jó, a kisebb (720p) a legrosszabb
            let sizeScore = f.height == preferredHeight ? 2 : (f.height > preferredHeight ? 1 : 0)
            let sharp: Double = f.binned ? 0 : 1
            // ha tudja a kért fps-t: az élesebb, majd a kisebb maximum (kevésbé „erőltetett” mód);
            // ha nem tudja: a nagyobb fps a fontosabb (a lassításhoz), utána az élesség
            return supports ? (1, sizeScore, sharp, -f.maxFPS) : (0, sizeScore, f.maxFPS, sharp)
        }
        return candidates.max { a, b in key(a) < key(b) }
    }
}
