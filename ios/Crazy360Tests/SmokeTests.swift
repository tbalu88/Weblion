import Foundation
import Testing
@testable import Crazy360

struct SmokeTests {
    @Test func hostAppIsLoaded() {
        #expect(Bundle.main.bundleIdentifier == "hu.eventcrazy.crazy360")
    }
}
