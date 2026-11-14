import Foundation
import Testing
@testable import SpinKit

struct SettingsTests {
    @Test func emptyJSONGivesDefaults() throws {
        let s = try JSONDecoder().decode(SpinSettings.self, from: Data("{}".utf8))
        #expect(s == SpinSettings())
    }

    @Test func partialWrongAndUnknownKeys() throws {
        let json = #"{"captureFPS":"sok","recordSeconds":12,"boomerang":false,"speedStyle":"ismeretlen","jovobeli":1,"captionTop":"Anna & Péter"}"#
        let s = try JSONDecoder().decode(SpinSettings.self, from: Data(json.utf8))
        #expect(s.captureFPS == 120)
        #expect(s.recordSeconds == 12)
        #expect(s.boomerang == false)
        #expect(s.speedStyle == .fastSlowFast)
        #expect(s.captionTop == "Anna & Péter")
    }

    @Test func roundTrip() throws {
        var s = SpinSettings()
        s.lens = .wide
        s.captureFPS = 240
        s.overlayFile = "keret.png"
        s.musicFile = "zene.m4a"
        s.captionColor = .gold
        let back = try JSONDecoder().decode(SpinSettings.self, from: try JSONEncoder().encode(s))
        #expect(back == s)
    }

    @Test func sanitizeClampsEverything() {
        var s = SpinSettings()
        s.captureFPS = 77
        s.outputFPS = 25
        s.countdown = 2
        s.recordSeconds = 999
        s.trimStart = -1
        s.trimEnd = .infinity
        s.slowFactor = 0
        s.musicVolume = 3
        s.captionTop = String(repeating: "é", count: 200)
        let c = s.sanitized()
        #expect(c.captureFPS == 120 && c.outputFPS == 30 && c.countdown == 3)
        #expect(c.recordSeconds == 30)
        #expect(c.trimStart == 0)
        #expect(c.trimEnd == 0.3)
        #expect(c.slowFactor == 0.1)
        #expect(c.musicVolume == 1)
        #expect(c.captionTop.count == 80)
        #expect(SpinSettings().sanitized() == SpinSettings())
    }

    @Test func choicesHaveTitles() {
        #expect(Lens.allCases.allSatisfy { !$0.title.isEmpty })
        #expect(CaptionColor.allCases.allSatisfy { !$0.title.isEmpty })
    }
}
