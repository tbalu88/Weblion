import AVFoundation
import Foundation
import SpinKit
import Testing
@testable import Crazy360

/// A renderelő a szimulátoron, mesterséges felvételekkel (a kamera nem kell hozzá).
@Suite(.timeLimit(.minutes(3)))
struct RendererTests {
    typealias RGB = TestMedia.RGB

    func request(source: URL, _ change: (inout SpinSettings) -> Void) -> RenderRequest {
        var s = SpinSettings()
        s.speedStyle = .normal
        s.boomerang = false
        s.trimStart = 0
        s.trimEnd = 0
        s.outputFPS = 30
        change(&s)
        return RenderRequest(source: source, output: TestMedia.tempURL("out.mp4"), poster: TestMedia.tempURL("poster.jpg"),
                             settings: s, frameImage: nil, music: nil, outputWidth: 180, outputHeight: 320)
    }

    /// 0,5 mp 120 fps-sel; az i. kocka szürkesége 10 + 4·i (kódolt sorszám).
    func codedSource() async throws -> URL {
        try await TestMedia.makeVideo(width: 320, height: 180, fps: 120, frames: 60) { i in
            let v = 10 + 4 * i
            return (RGB(r: v, g: v, b: v), RGB(r: v, g: v, b: v))
        }
    }

    @Test func boomerangPlaysForwardThenBackward() async throws {
        let src = try await codedSource()
        let req = request(source: src) { $0.boomerang = true }
        let result = try await VideoRenderer().render(req) { _ in }
        #expect(result.frames == 15 + 13)

        let levels = try await TestMedia.sample(req.output, points: [(0.5, 0.5)]).map { $0[0].g }
        #expect(levels.count == 28)
        // előre: a k. kimeneti kocka a 4k. forráskocka (10 + 16k); vissza: tükörkép a fordulópontok nélkül
        let forward = (0..<15).map { 10 + 16 * $0 }
        let expected = forward + forward[1..<14].reversed()
        for (got, want) in zip(levels, expected) {
            #expect(abs(got - want) <= 10, "kapott \(got), várt \(want)")
        }
        #expect(FileManager.default.fileExists(atPath: req.poster.path))
    }

    @Test func slowMotionLengthMatchesPlan() async throws {
        let src = try await codedSource()
        let req = request(source: src) {
            $0.speedStyle = .slow
            $0.slowFactor = 0.25
        }
        let result = try await VideoRenderer().render(req) { _ in }
        #expect(result.frames == 60)   // 0,5 mp × 4 × 30 fps
        let duration = try await AVURLAsset(url: req.output).load(.duration).seconds
        #expect(abs(duration - 2.0) < 0.05)
        // négyszeres lassításnál minden forráskocka egyszer jön, sorban
        let levels = try await TestMedia.sample(req.output, points: [(0.5, 0.5)]).map { $0[0].g }
        #expect(zip(levels, levels.dropFirst()).allSatisfy { $0 <= $1 + 6 })
        #expect(levels.last ?? 0 > 200)
    }

    @Test func portraitRecordingIsRotatedUpright() async throws {
        // fekvő kocka 90°-os elforgatással (mint az álló iPhone-felvétel): a kép bal fele piros, jobb fele kék
        let src = try await TestMedia.makeVideo(width: 320, height: 180, fps: 30, frames: 10,
                                                transform: CGAffineTransform(rotationAngle: .pi / 2)) { _ in
            (RGB(r: 230, g: 20, b: 20), RGB(r: 20, g: 20, b: 230))
        }
        let req = request(source: src) { _ in }
        _ = try await VideoRenderer().render(req) { _ in }
        let frames = try await TestMedia.sample(req.output, points: [(0.5, 0.2), (0.5, 0.8)])
        let first = try #require(frames.first)
        // álló helyzetben a kép bal széle kerül felülre
        #expect(first[0].r > 150 && first[0].b < 100, "felül: \(first[0])")
        #expect(first[1].b > 150 && first[1].r < 100, "alul: \(first[1])")
    }

    @Test func musicAndCaptionAreAdded() async throws {
        let src = try await codedSource()
        let tone = try TestMedia.makeTone(seconds: 0.6)   // rövidebb a videónál → ismétlődik
        var req = request(source: src) {
            $0.speedStyle = .slow
            $0.slowFactor = 0.5
            $0.boomerang = true
            $0.captionTop = "Anna & Péter"
            $0.captionBottom = "2026.11.30."
            $0.musicStart = 0.2
        }
        req.music = tone
        let progress = ProgressLog()
        let result = try await VideoRenderer().render(req) { p in progress.add(p) }
        let asset = AVURLAsset(url: req.output)
        let audio = try await asset.loadTracks(withMediaType: .audio)
        #expect(audio.count == 1)
        let audioTrack = try #require(audio.first)
        let audioSeconds = try await audioTrack.load(.timeRange).duration.seconds
        #expect(abs(audioSeconds - result.duration) < 0.15, "hang: \(audioSeconds), kép: \(result.duration)")
        #expect(progress.values.last == 1)
        #expect(zip(progress.values, progress.values.dropFirst()).allSatisfy { $0 <= $1 })
        // a felirat a felső sávban: világos pixelek a sötét kép fölött
        let frames = try await TestMedia.sample(req.output, points: [(0.5, 0.5)])
        #expect(!frames.isEmpty)
    }

    @Test func overlayBuilderDrawsOnlyWhenNeeded() throws {
        #expect(OverlayBuilder.make(width: 180, height: 320, frame: nil, top: "  ", bottom: "", color: .white) == nil)
        let img = try #require(OverlayBuilder.make(width: 180, height: 320, frame: nil, top: "Anna & Péter – nagyon hosszú felirat, ami nem fér ki",
                                                   bottom: "2026.11.30.", color: .gold))
        #expect(img.width == 180 && img.height == 320)
    }

    @Test func longCaptionsWrapIntoTwoBalancedLines() {
        let halves = OverlayBuilder.splitInTwo("Bartholomew & Aleksandra Wiśniewska-Kowalczyk – 2026. november 30., szombat")
        // a két sor hossza a lehető legközelebb egymáshoz (45 + 29 karakter)
        #expect(halves?.0 == "Bartholomew & Aleksandra Wiśniewska-Kowalczyk")
        #expect(halves?.1 == "– 2026. november 30., szombat")
        #expect(OverlayBuilder.splitInTwo("Egyszó") == nil)
        let pair = OverlayBuilder.splitInTwo("Anna Péter")
        #expect(pair?.0 == "Anna" && pair?.1 == "Péter")
    }

    @Test func factorLabels() {
        #expect(0.125.factorText == "0,125×")
        #expect(0.25.factorText == "0,25×")
        #expect((1.0 / 3.0).factorText == "0,33×")
        #expect(0.5.factorText == "0,5×")
    }

    @Test func orientationFromTransform() {
        let cases: [(CGAffineTransform, CGImagePropertyOrientation)] = [
            (CGAffineTransform(a: 0, b: 1, c: -1, d: 0, tx: 1080, ty: 0), .right),
            (CGAffineTransform(a: 0, b: -1, c: 1, d: 0, tx: 0, ty: 1920), .left),
            (CGAffineTransform(a: -1, b: 0, c: 0, d: -1, tx: 1920, ty: 1080), .down),
            (.identity, .up),
            (CGAffineTransform(rotationAngle: .pi / 2), .right),
        ]
        for (t, want) in cases {
            #expect(FrameGeometry.orientation(for: t) == want)
        }
    }

    @Test func heavyTrimLeavesOneFrame() async throws {
        let src = try await codedSource()
        let req = request(source: src) {
            $0.trimStart = 3
            $0.trimEnd = 3
        }
        // 0,5 mp-es felvétel 3+3 mp levágással: egy kocka marad – ez még nem hiba, de videó csak egy kocka
        let r = try await VideoRenderer().render(req) { _ in }
        #expect(r.frames == 1)
    }
}

/// Szálbiztos gyűjtő a folyamatjelzéshez (zárral védve, ezért @unchecked Sendable).
nonisolated final class ProgressLog: @unchecked Sendable {
    private let lock = NSLock()
    private var stored: [Double] = []

    func add(_ v: Double) {
        lock.lock()
        stored.append(v)
        lock.unlock()
    }

    var values: [Double] {
        lock.lock()
        defer { lock.unlock() }
        return stored
    }
}
