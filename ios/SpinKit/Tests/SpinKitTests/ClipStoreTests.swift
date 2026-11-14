import Foundation
import Testing
@testable import SpinKit

struct ClipStoreTests {
    let t0 = Date(timeIntervalSince1970: 1_800_000_000)

    func clip(_ id: String, _ offset: TimeInterval) -> Clip {
        Clip(id: id, ev: "abc123", deal: 5, date: "2026-11-30", eventTitle: "Próba", video: id + ".mp4", poster: id + ".jpg",
             createdAt: t0.addingTimeInterval(offset), duration: 12, bytes: 1000)
    }

    @Test func newestFirstOldestUploadsFirst() {
        var s = ClipStore()
        s.add(clip("aaaaaa", 0))
        s.add(clip("bbbbbb", 10))
        s.add(clip("cccccc", 20))
        #expect(s.clips.map(\.id) == ["cccccc", "bbbbbb", "aaaaaa"])
        #expect(s.nextToUpload(now: t0)?.id == "aaaaaa")
        #expect(s.pendingCount == 3)
        s.add(clip("aaaaaa", 30))   // ugyanaz az azonosító: csere, előre kerül
        #expect(s.clips.map(\.id) == ["aaaaaa", "cccccc", "bbbbbb"])
    }

    @Test func failureBacksOffAndWakes() {
        var s = ClipStore(clips: [clip("aaaaaa", 0)])
        s.markUploading(id: "aaaaaa")
        #expect(s.nextToUpload(now: t0) == nil)
        s.markFailed(id: "aaaaaa", message: "Nincs internet.", now: t0)
        #expect(s["aaaaaa"]?.attempts == 1)
        #expect(s["aaaaaa"]?.lastError == "Nincs internet.")
        #expect(s.nextToUpload(now: t0.addingTimeInterval(4)) == nil)
        #expect(s.nextToUpload(now: t0.addingTimeInterval(5))?.id == "aaaaaa")
        #expect(s.nextRetryDate() == t0.addingTimeInterval(5))
        s.markFailed(id: "aaaaaa", message: "x", now: t0)
        #expect(s.nextRetryDate() == t0.addingTimeInterval(15))
        s.wakeAll()
        #expect(s.nextToUpload(now: t0)?.id == "aaaaaa")
        #expect(s.nextRetryDate() == nil)
    }

    @Test func doneStoppedAndRetry() {
        var s = ClipStore(clips: [clip("aaaaaa", 0), clip("bbbbbb", 10)])
        s.markDone(id: "aaaaaa", url: "https://x/galeria/abc123/aaaaaa", at: t0)
        #expect(s["aaaaaa"]?.isUploaded == true)
        #expect(s.pendingCount == 1)
        s.markStopped(id: "bbbbbb", message: "Túl nagy videó.")
        #expect(s.pendingCount == 0)
        #expect(s.nextToUpload(now: t0) == nil)
        s.retryNow(id: "bbbbbb")
        s.retryNow(id: "aaaaaa")   // a feltöltöttet nem bántja
        #expect(s["aaaaaa"]?.isUploaded == true)
        #expect(s.nextToUpload(now: t0)?.id == "bbbbbb")
        s.remove(id: "bbbbbb")
        #expect(s.clips.count == 1)
        #expect(s["bbbbbb"] == nil)
    }

    @Test func interruptedUploadsResume() {
        var s = ClipStore(clips: [clip("aaaaaa", 0)])
        s.markUploading(id: "aaaaaa")
        s.resetInterrupted()
        #expect(s.nextToUpload(now: t0)?.id == "aaaaaa")
    }

    @Test func backoffSteps() {
        #expect((0...8).map { ClipStore.backoff(attempt: $0) } == [5, 5, 15, 30, 60, 120, 300, 300, 300])
    }

    @Test func roundTripsAsJSON() throws {
        var s = ClipStore(clips: [clip("aaaaaa", 0), clip("bbbbbb", 10), clip("cccccc", 20)])
        s.markDone(id: "aaaaaa", url: "https://x", at: t0)
        s.markStopped(id: "bbbbbb", message: "Túl nagy")
        s.markFailed(id: "cccccc", message: "Nincs net", now: t0)
        let e = JSONEncoder()
        e.dateEncodingStrategy = .iso8601
        let d = JSONDecoder()
        d.dateDecodingStrategy = .iso8601
        let back = try d.decode(ClipStore.self, from: try e.encode(s))
        #expect(back == s)
    }
}
