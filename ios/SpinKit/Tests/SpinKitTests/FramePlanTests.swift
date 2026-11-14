import Foundation
import Testing
@testable import SpinKit

struct FramePlanTests {
    @Test func normalSpeedKeepsRealTime() {
        let p = FramePlan.make(sourceDuration: 8, curve: .normal, outputFPS: 30, boomerang: false)
        #expect(p.sourceTimes.count == 240)
        #expect(p.forwardCount == 240)
        #expect(near(p.duration, 8))
        for (k, t) in p.sourceTimes.enumerated() {
            #expect(near(t, Double(k) / 30, 1e-3))
        }
    }

    @Test func trimShiftsAndShortens() {
        let p = FramePlan.make(sourceDuration: 8, trimStart: 0.5, trimEnd: 0.5, curve: .normal, outputFPS: 30, boomerang: false)
        #expect(p.sourceTimes.count == 210)
        #expect(near(p.sourceTimes[0], 0.5))
        #expect(near(p.sourceTimes[209], 0.5 + 209.0 / 30, 1e-3))
        #expect(p.sourceTimes.allSatisfy { $0 >= 0.5 && $0 <= 7.5 })
    }

    @Test func slowMotionStretchesTime() {
        let p = FramePlan.make(sourceDuration: 2, curve: SpeedStyle.slow.curve(slow: 0.25), outputFPS: 30, boomerang: false)
        #expect(p.sourceTimes.count == 240)
        #expect(near(p.sourceTimes[120], 1.0, 1e-3))
        #expect(near(p.sourceTimes[1] - p.sourceTimes[0], 0.25 / 30, 1e-4))
    }

    @Test(arguments: SpeedStyle.allCases)
    func forwardPartIsMonotonicAndInsideTrim(style: SpeedStyle) {
        let p = FramePlan.make(sourceDuration: 10, trimStart: 0.3, trimEnd: 0.4, curve: style.curve(slow: 0.25), outputFPS: 30, boomerang: true)
        let f = Array(p.forwardTimes)
        #expect(f.count >= 3)
        #expect(zip(f, f.dropFirst()).allSatisfy { $0 <= $1 })
        #expect(f.allSatisfy { $0 >= 0.3 - 1e-9 && $0 <= 9.6 + 1e-9 })
    }

    @Test func boomerangMirrorsWithoutTurningPoints() {
        let p = FramePlan.make(sourceDuration: 3, curve: .normal, outputFPS: 30, boomerang: true)
        let n = p.forwardCount
        #expect(n == 90)
        #expect(p.sourceTimes.count == n + n - 2)
        let reverse = Array(p.sourceTimes[n...])
        #expect(reverse == Array(p.sourceTimes[1..<(n - 1)].reversed()))
        #expect(p.reverseSourceIndices.first == n - 2)
        #expect(p.reverseSourceIndices.last == 1)
        for (i, src) in p.reverseSourceIndices.enumerated() {
            #expect(p.sourceTimes[n + i] == p.sourceTimes[src])
        }
    }

    @Test func boomerangNeedsAtLeastThreeFrames() {
        let p = FramePlan.make(sourceDuration: 2.0 / 30, curve: .normal, outputFPS: 30, boomerang: true)
        #expect(p.forwardCount == 2)
        #expect(p.sourceTimes.count == 2)
        #expect(p.reverseSourceIndices.isEmpty)
    }

    @Test func degenerateInputs() {
        #expect(FramePlan.make(sourceDuration: 0, curve: .normal, outputFPS: 30, boomerang: false).sourceTimes.isEmpty)
        #expect(FramePlan.make(sourceDuration: .nan, curve: .normal, outputFPS: 30, boomerang: false).sourceTimes.isEmpty)
        let overTrim = FramePlan.make(sourceDuration: 1, trimStart: 2, trimEnd: 2, curve: .normal, outputFPS: 30, boomerang: true)
        #expect(overTrim.sourceTimes == [1])
        let tiny = FramePlan.make(sourceDuration: 0.01, curve: .normal, outputFPS: 30, boomerang: false)
        #expect(tiny.sourceTimes.count == 1)
        let zeroFPS = FramePlan.make(sourceDuration: 1, curve: .normal, outputFPS: 0, boomerang: false)
        #expect(zeroFPS.fps == 1)
    }

    @Test func longOutputIsCapped() {
        let curve = SpeedStyle.slow.curve(slow: 0.125)   // 30 mp → 240 mp lenne
        let p = FramePlan.make(sourceDuration: 30, curve: curve, outputFPS: 30, boomerang: false, maxDuration: 90)
        #expect(near(p.duration, 90, 1.0 / 30))
        let b = FramePlan.make(sourceDuration: 30, curve: curve, outputFPS: 30, boomerang: true, maxDuration: 90)
        #expect(b.duration <= 90 + 1e-9)
        #expect(near(Double(b.forwardCount) / 30, 45, 1.0 / 30))
    }

    @Test func estimateMatchesPlan() {
        let s = SpinSettings()
        let est = s.estimatedOutputSeconds
        let p = FramePlan.make(sourceDuration: s.recordSeconds, trimStart: s.trimStart, trimEnd: s.trimEnd,
                               curve: s.curve, outputFPS: 30, boomerang: s.boomerang)
        #expect(abs(p.duration - est) <= 5.0 / 30)
        let length = s.recordSeconds - s.trimStart - s.trimEnd
        #expect(est > 2 * length)
        #expect(est < 2 * length * 4)
    }

    @Test func smoothestSlowdown() {
        #expect(near(FramePlan.smoothestSlowdown(sourceFPS: 120, outputFPS: 30), 0.25))
        #expect(near(FramePlan.smoothestSlowdown(sourceFPS: 240, outputFPS: 30), 0.125))
        #expect(near(FramePlan.smoothestSlowdown(sourceFPS: 60, outputFPS: 60), 1))
        #expect(near(FramePlan.smoothestSlowdown(sourceFPS: 0, outputFPS: 30), 1))
    }
}
