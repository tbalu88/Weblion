import Testing
@testable import SpinKit

struct NearestFramePickerTests {
    /// Végigfuttatja a választót, és visszaadja kimeneti indexenként a kapott forráskocka sorszámát.
    func run(frames: [Double], targets: [Double]) -> [Int] {
        var picker = NearestFramePicker(targets: targets)
        var out = [Int](repeating: -1, count: targets.count)
        for (j, t) in frames.enumerated() {
            for k in picker.feed(t) { out[k] = j - 1 }
        }
        for k in picker.finish() { out[k] = frames.count - 1 }
        return out
    }

    @Test func picksNearestFrame() {
        let frames = (0..<240).map { Double($0) / 120 }
        let targets = (0..<60).map { Double($0) / 30 + 0.001 }
        let got = run(frames: frames, targets: targets)
        for (k, j) in got.enumerated() {
            let best = frames.indices.min { abs(frames[$0] - targets[k]) < abs(frames[$1] - targets[k]) }
            #expect(j == best)
        }
    }

    @Test func slowMotionRepeatsFrames() {
        let frames = [0.0, 0.1, 0.2]
        let targets = [0.0, 0.02, 0.04, 0.06, 0.08, 0.1, 0.12]
        #expect(run(frames: frames, targets: targets) == [0, 0, 0, 1, 1, 1, 1])
    }

    @Test func targetsOutsideTheSourceUseTheEdges() {
        let frames = [1.0, 1.5, 2.0]
        let targets = [0.0, 0.5, 2.5, 3.0]
        #expect(run(frames: frames, targets: targets) == [0, 0, 2, 2])
    }

    @Test func doneStopsEarlyAndEmptyIsSafe() {
        var p = NearestFramePicker(targets: [0.0])
        #expect(p.feed(0).isEmpty)
        #expect(p.feed(1) == 0..<1)
        #expect(p.isDone)
        var e = NearestFramePicker(targets: [Double]())
        #expect(e.isDone)
        #expect(e.finish().isEmpty)
        var none = NearestFramePicker(targets: [1.0])
        #expect(none.finish().isEmpty)   // nem jött kocka
    }
}
