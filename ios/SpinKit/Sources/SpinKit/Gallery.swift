import Foundation

/// A felhő-galéria címei (a tartalék szerveren: mu-plugins/crazy-galeria.php).
public struct GalleryEndpoint: Sendable, Hashable {
    /// Alapcím, pl. https://tartalek.eventcrazy.hu
    public let base: URL

    public static let defaultBase = "https://tartalek.eventcrazy.hu"

    public init(base: URL) {
        self.base = base
    }

    /// Szövegből (beállítások); hibás címnél az alapértelmezett.
    public init(string: String) {
        let trimmed = string.trimmingCharacters(in: .whitespacesAndNewlines)
        if let u = URL(string: trimmed), let scheme = u.scheme?.lowercased(), scheme == "https", u.host != nil {
            base = u
        } else {
            base = URL(string: Self.defaultBase)!   // állandó, érvényes cím
        }
    }

    private var api: URL { base.appendingPathComponent("wp-json/crazy-galeria/v1") }

    /// A nap foglalt rendezvényei (a panel relén át, gépkulccsal).
    public func eventsURL(date: String) -> URL {
        var c = URLComponents(url: api.appendingPathComponent("m/events"), resolvingAgainstBaseURL: false)
        c?.queryItems = [URLQueryItem(name: "date", value: date)]
        return c?.url ?? api.appendingPathComponent("m/events")
    }

    /// Feltöltés (multipart).
    public var uploadURL: URL { api.appendingPathComponent("upload") }

    /// Egy videó oldala (ide mutat a QR).
    public func clipURL(ev: String, photo: String) -> URL {
        base.appendingPathComponent("galeria/\(ev)/\(photo)")
    }

    /// A rendezvény galériája.
    public func galleryURL(ev: String) -> URL {
        base.appendingPathComponent("galeria/\(ev)", isDirectory: true)
    }

    /// Megosztó állomás (iPad): a legfrissebb képek és videók QR-ral.
    public func stationURL(ev: String) -> URL {
        URL(string: galleryURL(ev: ev).absoluteString + "?allomas=1") ?? galleryURL(ev: ev)
    }
}

/// Egy rendezvény a panel listájából (`/m/events`).
public struct GalleryEvent: Codable, Sendable, Hashable, Identifiable {
    /// Galéria-azonosító ([a-z0-9]{6,32}).
    public var ev: String
    /// A panel dealje (0 = rendezvény nélkül).
    public var deal: Int
    public var title: String
    /// A rendezvény napja (ÉÉÉÉ-HH-NN).
    public var date: String
    public var time: String?
    public var loc: String?

    public var id: String { ev }

    public init(ev: String, deal: Int, title: String, date: String, time: String? = nil, loc: String? = nil) {
        self.ev = ev
        self.deal = deal
        self.title = title
        self.date = date
        self.time = time
        self.loc = loc
    }

    /// „Rendezvény nélkül” – saját, véletlen galéria-azonosítóval (mint a mobil appban).
    public static func standalone(date: String) -> GalleryEvent {
        GalleryEvent(ev: "m" + Token.make(12), deal: 0, title: "Rendezvény nélkül", date: date)
    }
}

/// A `/m/events` válasza.
public struct EventsResponse: Decodable, Sendable {
    public struct Item: Decodable, Sendable {
        public var ev: String
        public var deal: Int
        public var title: String
        public var time: String?
        public var loc: String?

        enum CodingKeys: String, CodingKey { case ev, deal, title, time, loc }

        public init(from decoder: any Decoder) throws {
            let c = try decoder.container(keyedBy: CodingKeys.self)
            ev = try c.decode(String.self, forKey: .ev)
            // a panel számot küld, de szövegként is elfogadjuk
            if let n = try? c.decode(Int.self, forKey: .deal) {
                deal = n
            } else {
                deal = Int((try? c.decode(String.self, forKey: .deal)) ?? "") ?? 0
            }
            title = (try? c.decode(String.self, forKey: .title)) ?? ""
            time = try? c.decodeIfPresent(String.self, forKey: .time)
            loc = try? c.decodeIfPresent(String.self, forKey: .loc)
        }
    }

    public var ok: Bool?
    public var message: String?
    public var events: [Item]?

    /// A lista `GalleryEvent`-ként, a kért nappal; hibás azonosítójú tételek nélkül.
    public func galleryEvents(date: String) -> [GalleryEvent] {
        (events ?? []).compactMap { e in
            guard Token.isValid(e.ev) else { return nil }
            let title = e.title.trimmingCharacters(in: .whitespacesAndNewlines)
            return GalleryEvent(ev: e.ev, deal: e.deal, title: title.isEmpty ? "Névtelen rendezvény" : title, date: date,
                                time: e.time.flatMap { $0.isEmpty ? nil : $0 }, loc: e.loc.flatMap { $0.isEmpty ? nil : $0 })
        }
    }
}

/// A `/upload` (és a hibák) válasza.
public struct UploadResponse: Decodable, Sendable {
    public var ok: Bool?
    public var url: String?
    public var message: String?
    public var dup: Bool?
}

/// Azonosítók (galéria, kép): kisbetű + szám, a szerver `^[a-z0-9]{6,32}$` szabálya szerint.
public enum Token {
    static let alphabet = Array("abcdefghijklmnopqrstuvwxyz0123456789")

    public static func make(_ length: Int = 12) -> String {
        var g = SystemRandomNumberGenerator()
        return String((0..<max(6, min(32, length))).map { _ in alphabet[Int(g.next(upperBound: UInt(alphabet.count)))] })
    }

    public static func isValid(_ s: String) -> Bool {
        (6...32).contains(s.count) && s.allSatisfy { ("a"..."z").contains($0) || ("0"..."9").contains($0) }
    }
}

/// Dátum szövegként (ÉÉÉÉ-HH-NN), a készülék időzónájában.
public enum DayString {
    public static func from(_ date: Date, calendar: Calendar = .current) -> String {
        let c = calendar.dateComponents([.year, .month, .day], from: date)
        return String(format: "%04d-%02d-%02d", c.year ?? 1970, c.month ?? 1, c.day ?? 1)
    }

    /// Magyar formában: 2026.11.30.
    public static func hungarian(_ day: String) -> String {
        day.isEmpty ? "" : day.replacingOccurrences(of: "-", with: ".") + "."
    }
}
