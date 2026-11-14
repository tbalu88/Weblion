import Foundation
import Testing
@testable import SpinKit

struct GalleryTests {
    let g = GalleryEndpoint(string: "https://tartalek.eventcrazy.hu")

    @Test func links() {
        #expect(g.clipURL(ev: "abc123", photo: "xyz789qq").absoluteString == "https://tartalek.eventcrazy.hu/galeria/abc123/xyz789qq")
        #expect(g.galleryURL(ev: "abc123").absoluteString == "https://tartalek.eventcrazy.hu/galeria/abc123/")
        #expect(g.stationURL(ev: "abc123").absoluteString == "https://tartalek.eventcrazy.hu/galeria/abc123/?allomas=1")
        #expect(g.uploadURL.absoluteString == "https://tartalek.eventcrazy.hu/wp-json/crazy-galeria/v1/upload")
        #expect(g.eventsURL(date: "2026-11-30").absoluteString == "https://tartalek.eventcrazy.hu/wp-json/crazy-galeria/v1/m/events?date=2026-11-30")
    }

    @Test func trailingSlashAndBadBase() {
        let s = GalleryEndpoint(string: " https://galeria.eventcrazy.hu/ ")
        #expect(s.clipURL(ev: "abc123", photo: "def456").absoluteString == "https://galeria.eventcrazy.hu/galeria/abc123/def456")
        #expect(GalleryEndpoint(string: "http://nem-biztonsagos.hu").base.absoluteString == GalleryEndpoint.defaultBase)
        #expect(GalleryEndpoint(string: "").base.absoluteString == GalleryEndpoint.defaultBase)
        #expect(GalleryEndpoint(string: "szemét").base.absoluteString == GalleryEndpoint.defaultBase)
    }

    @Test func eventsDecodeLeniently() throws {
        let json = """
        {"ok":true,"date":"2026-11-30","events":[
          {"deal":123,"title":"Anna & Péter esküvője","time":"18:00","kind":"wedding","loc":"Budapest","template":true,"ev":"k3x9abc123","gallery":"x"},
          {"deal":"77","title":"  ","time":"","loc":null,"ev":"zz88yy77xx"},
          {"deal":5,"title":"Rossz azonosító","ev":"ROSSZ!"}
        ]}
        """
        let r = try JSONDecoder().decode(EventsResponse.self, from: Data(json.utf8))
        let list = r.galleryEvents(date: "2026-11-30")
        #expect(list.count == 2)
        #expect(list[0] == GalleryEvent(ev: "k3x9abc123", deal: 123, title: "Anna & Péter esküvője", date: "2026-11-30", time: "18:00", loc: "Budapest"))
        #expect(list[1].deal == 77)
        #expect(list[1].title == "Névtelen rendezvény")
        #expect(list[1].time == nil)
        #expect(list[1].loc == nil)
    }

    @Test func errorResponseDecodes() throws {
        let r = try JSONDecoder().decode(EventsResponse.self, from: Data(#"{"ok":false,"message":"Hiányzó vagy hibás gépkulcs."}"#.utf8))
        #expect(r.ok == false)
        #expect(r.message == "Hiányzó vagy hibás gépkulcs.")
        #expect(r.galleryEvents(date: "2026-01-01").isEmpty)
        let u = try JSONDecoder().decode(UploadResponse.self, from: Data(#"{"ok":true,"dup":true,"url":"https://x/galeria/a/b"}"#.utf8))
        #expect(u.ok == true && u.dup == true && u.url == "https://x/galeria/a/b")
    }

    @Test func tokens() {
        for _ in 0..<50 {
            let t = Token.make(12)
            #expect(t.count == 12)
            #expect(Token.isValid(t))
        }
        #expect(Token.make(2).count == 6)
        #expect(Token.make(99).count == 32)
        #expect(!Token.isValid("abc"))
        #expect(!Token.isValid("ABCDEF12"))
        #expect(!Token.isValid("árvíztű"))
        #expect(!Token.isValid(String(repeating: "a", count: 33)))
        let s = GalleryEvent.standalone(date: "2026-11-30")
        #expect(s.ev.hasPrefix("m") && Token.isValid(s.ev) && s.deal == 0)
    }

    @Test func days() {
        var cal = Calendar(identifier: .gregorian)
        cal.timeZone = TimeZone(identifier: "Europe/Budapest")!
        let d = cal.date(from: DateComponents(year: 2026, month: 3, day: 7, hour: 23, minute: 30))!
        #expect(DayString.from(d, calendar: cal) == "2026-03-07")
        #expect(DayString.hungarian("2026-03-07") == "2026.03.07.")
        #expect(DayString.hungarian("") == "")
    }
}
