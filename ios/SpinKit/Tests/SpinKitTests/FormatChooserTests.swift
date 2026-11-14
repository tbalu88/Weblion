import Testing
@testable import SpinKit

struct FormatChooserTests {
    // egy tipikus iPhone ultra-wide formátumlistája (egyszerűsítve)
    let formats = [
        CameraFormatInfo(width: 1280, height: 720, maxFPS: 240, binned: true, eightBit: true),       // 0
        CameraFormatInfo(width: 1920, height: 1080, maxFPS: 30, binned: false, eightBit: true),      // 1
        CameraFormatInfo(width: 1920, height: 1080, maxFPS: 60, binned: false, eightBit: true),      // 2
        CameraFormatInfo(width: 1920, height: 1080, maxFPS: 120, binned: true, eightBit: true),      // 3
        CameraFormatInfo(width: 1920, height: 1080, maxFPS: 120, binned: false, eightBit: false),    // 4 (10 bit)
        CameraFormatInfo(width: 3840, height: 2160, maxFPS: 60, binned: false, eightBit: true),      // 5
    ]

    @Test func picks1080pForRequestedFPS() {
        #expect(FormatChooser.best(formats, fps: 60) == 2)
        #expect(FormatChooser.best(formats, fps: 30) == 1)
        #expect(FormatChooser.best(formats, fps: 120) == 3)   // a 10 bites nem jó, a binned 1080p igen
    }

    @Test func fallsBackToFastestWhenUnsupported() {
        // 240 fps-t csak a 720p tud: azt kapjuk (a felület kiírja)
        #expect(FormatChooser.best(formats, fps: 240) == 0)
        let no240 = Array(formats[1...])
        #expect(FormatChooser.best(no240, fps: 240).map { no240[$0].maxFPS } == 120)
    }

    @Test func emptyAndOnlyTenBit() {
        #expect(FormatChooser.best([], fps: 60) == nil)
        let ten = [CameraFormatInfo(width: 1920, height: 1080, maxFPS: 60, binned: false, eightBit: false)]
        #expect(FormatChooser.best(ten, fps: 60) == 0)
    }
}
