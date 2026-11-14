import Foundation

/// A kész videó képkockáinak terve: kimeneti kockánként a forrásvideó melyik pillanata (mp, a forrásfájl elejétől).
///
/// Az előre menő rész a sebesség-ív szerint halad; oda-vissza esetén utána ugyanez visszafelé jön, a két fordulópont
/// kockája nélkül (így ismétlődő lejátszásnál sem akad meg).
public struct FramePlan: Sendable, Equatable {
    /// Kimeneti kockánként a forrásidő.
    public let sourceTimes: [Double]
    /// Ennyi kocka az előre menő rész (a többi a visszafelé menő).
    public let forwardCount: Int
    /// Kimeneti képkocka / mp.
    public let fps: Int

    public init(sourceTimes: [Double], forwardCount: Int, fps: Int) {
        self.sourceTimes = sourceTimes
        self.forwardCount = forwardCount
        self.fps = fps
    }

    /// A kész videó hossza (mp).
    public var duration: Double { fps > 0 ? Double(sourceTimes.count) / Double(fps) : 0 }

    /// Az előre menő rész forrásidői (nem csökkenő sorrend).
    public var forwardTimes: ArraySlice<Double> { sourceTimes[..<forwardCount] }

    /// A visszafelé menő rész: kimeneti index → az előre menő rész melyik kockáját ismétli fordítva.
    /// (Az oda-vissza rész nem olvas újra a forrásból: a már elkészült előre menő kockákat használja.)
    public var reverseSourceIndices: [Int] {
        guard sourceTimes.count > forwardCount else { return [] }
        let n = sourceTimes.count - forwardCount
        return (0..<n).map { forwardCount - 2 - $0 }
    }

    /// Terv készítése.
    /// - Parameters:
    ///   - sourceDuration: a felvétel hossza (mp)
    ///   - trimStart / trimEnd: ennyi mp-et vágunk le az elejéről / a végéről (a kar indulása, megállása)
    ///   - curve: sebesség-ív
    ///   - outputFPS: kimeneti fps (30 / 60)
    ///   - boomerang: oda-vissza
    ///   - maxDuration: a kész videó legnagyobb hossza (mp) – a galéria 80 MB-os korlátja miatt
    public static func make(sourceDuration: Double, trimStart: Double = 0, trimEnd: Double = 0,
                            curve: SpeedCurve, outputFPS: Int, boomerang: Bool,
                            maxDuration: Double = 90) -> FramePlan {
        let fps = max(1, outputFPS)
        guard sourceDuration.isFinite, sourceDuration > 0 else { return FramePlan(sourceTimes: [], forwardCount: 0, fps: fps) }
        let start = min(max(0, trimStart), sourceDuration)
        let end = max(start, sourceDuration - max(0, trimEnd))
        let length = end - start
        guard length > 0 else { return FramePlan(sourceTimes: [start], forwardCount: 1, fps: fps) }

        let table = OutputTimeTable(length: length, curve: curve)
        let cap = max(1 / Double(fps), boomerang ? maxDuration / 2 : maxDuration)
        let total = min(table.total, cap)
        // kis tűrés: a sok összeadás kerekítési hibája miatt a 8,0 mp ne 239 kocka legyen
        let count = max(1, Int((total * Double(fps) + 1e-6).rounded(.down)))

        var times: [Double] = []
        times.reserveCapacity(boomerang ? count * 2 : count)
        var cursor = 0
        for k in 0..<count {
            let u = table.sourceFraction(atOutputTime: Double(k) / Double(fps), cursor: &cursor)
            times.append(start + u * length)
        }
        let forward = times.count
        if boomerang && forward >= 3 {
            times.append(contentsOf: times[1..<(forward - 1)].reversed())
        }
        return FramePlan(sourceTimes: times, forwardCount: forward, fps: fps)
    }

    /// A kész videó várható hossza (mp) – a beállítások képernyőhöz, terv nélkül.
    public static func estimatedDuration(recordSeconds: Double, trimStart: Double, trimEnd: Double,
                                         curve: SpeedCurve, boomerang: Bool, maxDuration: Double = 90) -> Double {
        let length = max(0, recordSeconds - max(0, trimStart) - max(0, trimEnd))
        guard length > 0 else { return 0 }
        let total = OutputTimeTable(length: length, curve: curve).total
        return boomerang ? min(total, maxDuration / 2) * 2 : min(total, maxDuration)
    }

    /// A leglassabb „sima” sebesség: ennél lassabban a forrás kockái ismétlődnek (akadás).
    /// Pl. 120 fps-es felvétel 30 fps-es kimenetben: 0,25.
    public static func smoothestSlowdown(sourceFPS: Double, outputFPS: Int) -> Double {
        guard sourceFPS > 0 else { return 1 }
        return min(1, Double(outputFPS) / sourceFPS)
    }
}

/// A forrás → kimenet időtábla: a kimeneti idő a forrás mentén ∫ 1/sebesség, finom rácson integrálva.
struct OutputTimeTable {
    let steps: Int
    /// Kimeneti idő (mp) a rács pontjain; `cumulative[i]` a forrás `i/steps` hányadánál.
    let cumulative: [Double]

    init(length: Double, curve: SpeedCurve) {
        steps = 2000
        var c = [Double](repeating: 0, count: steps + 1)
        let du = length / Double(steps)
        for i in 1...steps {
            let mid = (Double(i) - 0.5) / Double(steps)
            c[i] = c[i - 1] + du / curve.speed(at: mid)
        }
        cumulative = c
    }

    var total: Double { cumulative[steps] }

    /// A forrás hányada (0…1) a `t` kimeneti időnél. A `cursor` a következő hívás gyorsításához (növekvő `t`-kre).
    func sourceFraction(atOutputTime t: Double, cursor: inout Int) -> Double {
        if t <= 0 { return 0 }
        if t >= total { return 1 }
        var j = min(max(cursor, 0), steps - 1)
        if cumulative[j] > t { j = 0 }
        while j < steps - 1 && cumulative[j + 1] < t { j += 1 }
        cursor = j
        let segment = cumulative[j + 1] - cumulative[j]
        let f = segment > 0 ? (t - cumulative[j]) / segment : 0
        return min(1, (Double(j) + min(max(f, 0), 1)) / Double(steps))
    }
}
