import Testing
@testable import SpinKit

struct SpeedCurveTests {
    @Test func keysAreHit() {
        let c = SpeedStyle.fastSlowFast.curve(slow: 0.25)
        #expect(near(c.speed(at: 0), 1))
        #expect(near(c.speed(at: 0.22), 1))
        #expect(near(c.speed(at: 0.5), 0.25))
        #expect(near(c.speed(at: 1), 1))
        #expect(near(c.slowest, 0.25))
    }

    @Test func rampIsSmoothAndMonotonic() {
        let c = SpeedStyle.fastSlowFast.curve(slow: 0.25)
        let samples = stride(from: 0.22, through: 0.38, by: 0.005).map { c.speed(at: $0) }
        #expect(zip(samples, samples.dropFirst()).allSatisfy { $0 >= $1 - 1e-12 })
        // a végpontokon lapos (smoothstep): az első lépés kicsi
        #expect(abs(samples[1] - samples[0]) < 0.01)
    }

    @Test func unsortedKeysAndClamping() {
        let c = SpeedCurve(keys: [.init(at: 1, speed: 100), .init(at: 0, speed: 0)])
        #expect(c.keys.map(\.at) == [0, 1])
        #expect(near(c.speed(at: 0), SpeedCurve.speedRange.lowerBound))
        #expect(near(c.speed(at: 1), SpeedCurve.speedRange.upperBound))
        #expect(near(SpeedCurve(keys: []).speed(at: 0.5), 1))
        #expect(near(SpeedCurve(keys: [.init(at: 0.5, speed: .nan)]).speed(at: 0.2), 1))
    }

    @Test(arguments: SpeedStyle.allCases)
    func everyStyleHasTitleAndPositiveSpeeds(style: SpeedStyle) {
        #expect(!style.title.isEmpty)
        let c = style.curve(slow: 1.0 / 3.0)
        for u in stride(from: 0.0, through: 1.0, by: 0.05) {
            #expect(c.speed(at: u) > 0)
        }
    }
}
