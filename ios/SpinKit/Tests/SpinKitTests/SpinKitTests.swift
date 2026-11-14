import Testing
@testable import SpinKit

@Test func versionHasThreeParts() {
    #expect(SpinKit.version.split(separator: ".").count == 3)
}
