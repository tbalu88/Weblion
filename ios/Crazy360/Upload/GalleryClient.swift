import Foundation
import SpinKit

/// A felhő-galéria hívásai gépkulccsal: a nap rendezvényei és a videó-feltöltés.
nonisolated struct GalleryClient: Sendable {
    nonisolated enum Failure: LocalizedError, Equatable {
        /// Hiányzó, hibás vagy letiltott gépkulcs (401) – amíg nem javítják, nincs értelme újrapróbálni.
        case badKey(String)
        /// Nincs (jó) internet – később újra.
        case offline
        /// A szerver hibát adott – később újra.
        case server(String)
        /// Végleges hiba (pl. túl nagy fájl) – magától nem próbáljuk újra.
        case rejected(String)

        var errorDescription: String? {
            switch self {
            case .badKey(let m): m.isEmpty ? "Hibás vagy letiltott gépkulcs." : m
            case .offline: "Nincs internetkapcsolat."
            case .server(let m): m.isEmpty ? "A galéria most nem érhető el." : m
            case .rejected(let m): m.isEmpty ? "A galéria nem fogadta el a videót." : m
            }
        }
    }

    let endpoint: GalleryEndpoint
    let key: String
    var session: URLSession = .shared

    /// A nap foglalt rendezvényei (a panelből).
    func events(date: String) async throws -> [GalleryEvent] {
        var request = URLRequest(url: endpoint.eventsURL(date: date), cachePolicy: .reloadIgnoringLocalCacheData, timeoutInterval: 25)
        request.setValue(key, forHTTPHeaderField: "X-Booth-Key")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        let (data, response) = try await send { try await session.data(for: request) }
        let body = try? JSONDecoder().decode(EventsResponse.self, from: data)
        try check(response, message: body?.message)
        guard let body, body.ok != false else { throw Failure.server(body?.message ?? "") }
        return body.galleryEvents(date: date)
    }

    /// Videó + borítókép feltöltése. Visszaadja a videó galéria-linkjét.
    @concurrent
    func upload(_ clip: Clip, video: URL, poster: URL?) async throws -> String {
        let body = Self.uploadBody(clip, video: video, poster: poster)
        try? FileManager.default.createDirectory(at: Paths.uploads, withIntermediateDirectories: true)
        let bodyFile = Paths.uploads.appendingPathComponent("\(clip.id).body")
        try body.write(to: bodyFile)
        defer { try? FileManager.default.removeItem(at: bodyFile) }

        var request = URLRequest(url: endpoint.uploadURL, cachePolicy: .reloadIgnoringLocalCacheData, timeoutInterval: 300)
        request.httpMethod = "POST"
        request.setValue(key, forHTTPHeaderField: "X-Booth-Key")
        request.setValue(body.contentType, forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        let (data, response) = try await send { try await session.upload(for: request, fromFile: bodyFile) }
        let reply = try? JSONDecoder().decode(UploadResponse.self, from: data)
        try check(response, message: reply?.message)
        guard let reply, reply.ok == true else { throw Failure.server(reply?.message ?? "") }
        return reply.url ?? endpoint.clipURL(ev: clip.ev, photo: clip.id).absoluteString
    }

    /// A feltöltés mezői – ugyanazok, mint a mobil appban (ev, photo, date, deal, kind=video, file, poster).
    static func uploadBody(_ clip: Clip, video: URL, poster: URL?) -> MultipartBody {
        var parts: [MultipartBody.Part] = [
            .field(name: "ev", value: clip.ev),
            .field(name: "photo", value: clip.id),
            .field(name: "date", value: clip.date),
            .field(name: "deal", value: String(clip.deal)),
            .field(name: "kind", value: "video"),
            .file(name: "file", filename: "\(clip.id).mp4", contentType: "video/mp4", source: video),
        ]
        if let poster, FileManager.default.fileExists(atPath: poster.path) {
            parts.append(.file(name: "poster", filename: "\(clip.id).jpg", contentType: "image/jpeg", source: poster))
        }
        return MultipartBody(parts: parts)
    }

    /// Hálózati hibák egységesítése.
    private func send(_ call: () async throws -> (Data, URLResponse)) async throws -> (Data, URLResponse) {
        do {
            return try await call()
        } catch let error as URLError {
            switch error.code {
            case .cancelled: throw CancellationError()
            case .notConnectedToInternet, .networkConnectionLost, .timedOut, .cannotFindHost, .cannotConnectToHost,
                 .dnsLookupFailed, .internationalRoamingOff, .dataNotAllowed, .secureConnectionFailed:
                throw Failure.offline
            default:
                throw Failure.server(error.localizedDescription)
            }
        }
    }

    private func check(_ response: URLResponse, message: String?) throws {
        guard let http = response as? HTTPURLResponse else { throw Failure.server("") }
        switch http.statusCode {
        case 200..<300: return
        case 401, 403: throw Failure.badKey(message ?? "")
        case 400, 413, 415: throw Failure.rejected(message ?? "")
        default: throw Failure.server(message ?? "HTTP \(http.statusCode)")
        }
    }
}
