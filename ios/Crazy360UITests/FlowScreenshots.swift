import XCTest

/// A teljes folyamat a szimulátoron (kamera helyett próbafelvétellel), képernyőképekkel a CI eredményében.
final class FlowScreenshots: XCTestCase {
    override func setUp() {
        continueAfterFailure = false
    }

    @MainActor
    func testRecordingFlow() throws {
        let app = XCUIApplication()
        app.launchArguments = ["-uitest"]
        app.launch()

        let shutter = app.buttons["Felvétel indítása"]
        XCTAssertTrue(shutter.waitForExistence(timeout: 20))
        snap(app, "1-kamera")

        shutter.tap()
        sleep(1)
        snap(app, "2-visszaszamlalas")

        let stop = app.buttons["Felvétel leállítása"]
        if stop.waitForExistence(timeout: 10) { snap(app, "3-felvetel") }

        // a feldolgozás képernyője egy összevont akadálymentes elem („Készül a videó, 42 %, …”)
        let processing = app.descendants(matching: .any).matching(NSPredicate(format: "label BEGINSWITH 'Készül a videó'")).firstMatch
        if processing.waitForExistence(timeout: 60) {
            sleep(1)
            snap(app, "4-feldolgozas")
        }

        let again = app.buttons["Új felvétel"]
        XCTAssertTrue(again.waitForExistence(timeout: 240), "Nem készült el a videó")
        sleep(2)
        snap(app, "5-eredmeny")

        app.buttons["Vissza a kamerához"].tap()
        XCTAssertTrue(shutter.waitForExistence(timeout: 10))
        snap(app, "6-kamera-utana")

        app.buttons["Beállítások"].firstMatch.tap()
        let done = app.buttons["Kész"]
        XCTAssertTrue(done.waitForExistence(timeout: 10))
        sleep(1)
        snap(app, "7-beallitasok")
        app.swipeUp()
        sleep(1)
        snap(app, "8-beallitasok-effekt")
        app.swipeUp()
        sleep(1)
        snap(app, "9-beallitasok-keret")
        app.swipeUp()
        sleep(1)
        snap(app, "10-beallitasok-zene")

        let videos = app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Videók'")).firstMatch
        if videos.waitForExistence(timeout: 5) {
            videos.tap()
            sleep(2)
            snap(app, "11-videok")
        }
    }

    @MainActor
    private func snap(_ app: XCUIApplication, _ name: String) {
        let shot = XCTAttachment(screenshot: app.screenshot())
        shot.name = name
        shot.lifetime = .keepAlways
        add(shot)
    }
}

/// break-ui: a legrosszabb valószerű adatokkal (hosszú nevek, 200 videó, minden feltöltési állapot).
final class WorstCaseScreenshots: XCTestCase {
    override func setUp() {
        continueAfterFailure = false
    }

    @MainActor
    func testWorstCaseScreens() throws {
        let app = XCUIApplication()
        app.launchArguments = ["-uitest-worst"]
        app.launch()
        XCTAssertTrue(app.buttons["Felvétel indítása"].waitForExistence(timeout: 20))
        sleep(1)
        snap(app, "w1-kamera")
        app.buttons["Beállítások"].firstMatch.tap()
        XCTAssertTrue(app.buttons["Kész"].waitForExistence(timeout: 10))
        sleep(1)
        snap(app, "w2-beallitasok")
        app.swipeUp()
        sleep(1)
        snap(app, "w3-beallitasok-effekt")
        app.swipeUp()
        sleep(1)
        snap(app, "w4-beallitasok-keret")
        app.buttons["Kész"].tap()
        sleep(1)
        app.buttons["Videók"].firstMatch.tap()
        sleep(2)
        snap(app, "w5-videok")
    }

    @MainActor
    private func snap(_ app: XCUIApplication, _ name: String) {
        let shot = XCTAttachment(screenshot: app.screenshot())
        shot.name = name
        shot.lifetime = .keepAlways
        add(shot)
    }
}
