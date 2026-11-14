import Foundation
import SpinKit
import Testing
@testable import Crazy360

/// Hálózati mock: tesztenként saját (véletlen) hoszt, így a tesztek párhuzamosan is futhatnak.
nonisolated final class MockRoutes: @unchecked Sendable {
    typealias Handler = @Sendable (URLRequest, Data) -> Result<(Int, Data), URLError>
    static let shared = MockRoutes()
    private let lock = NSLock()
    private var handlers: [String: Handler] = [:]

    func set(_ host: String, _ handler: @escaping Handler) {
        lock.lock()
        handlers[host] = handler
        lock.unlock()
    }

    func handler(for host: String) -> Handler? {
        lock.lock()
        defer { lock.unlock() }
        return handlers[host]
    }
}

nonisolated final class MockURLProtocol: URLProtocol {
    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }

    override func startLoading() {
        guard let url = request.url, let handler = MockRoutes.shared.handler(for: url.host ?? "") else {
            client?.urlProtocol(self, didFailWithError: URLError(.cannotFindHost))
            return
        }
        // a feltöltés törzsét (fájlból, folyamként) itt nem olvassuk: az olvasás a munkamenet szálán elakadhat –
        // a törzset külön teszt ellenőrzi (uploadBodyHasTheGalleryFields)
        switch handler(request, request.httpBody ?? Data()) {
        case .success(let (code, data)):
            guard let response = HTTPURLResponse(url: url, statusCode: code, httpVersion: "HTTP/1.1",
                                                 headerFields: ["Content-Type": "application/json"]) else { return }
            client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
            client?.urlProtocol(self, didLoad: data)
            client?.urlProtocolDidFinishLoading(self)
        case .failure(let error):
            client?.urlProtocol(self, didFailWithError: error)
        }
    }

    override func stopLoading() {}
}

/// Szálbiztos rekesz (a mock kezelője más szálon fut).
nonisolated final class Box<T: Sendable>: @unchecked Sendable {
    private let lock = NSLock()
    private var stored: T

    init(_ value: T) { stored = value }

    var value: T {
        get { lock.lock(); defer { lock.unlock() }; return stored }
        set { lock.lock(); stored = newValue; lock.unlock() }
    }
}

@Suite(.timeLimit(.minutes(1)))
struct GalleryClientTests {
    let key = "cb_teszt_kulcs_0123456789"

    func makeClient() -> (GalleryClient, String) {
        let host = "t\(UUID().uuidString.prefix(8).lowercased()).example"
        let config = URLSessionConfiguration.ephemeral
        config.protocolClasses = [MockURLProtocol.self]
        let base = URL(string: "https://\(host)") ?? URL(fileURLWithPath: "/")
        return (GalleryClient(endpoint: GalleryEndpoint(base: base), key: key, session: URLSession(configuration: config)), host)
    }

    @Test func eventsSendTheKeyAndParse() async throws {
        let (client, host) = makeClient()
        let seen = Box("")
        MockRoutes.shared.set(host) { request, _ in
            seen.value = (request.value(forHTTPHeaderField: "X-Booth-Key") ?? "") + "|" + (request.url?.query ?? "")
            return .success((200, Data(#"{"ok":true,"events":[{"deal":7,"title":"Teszt rendezvény","ev":"abc123xyz","time":"18:00","loc":""}]}"#.utf8)))
        }
        let list = try await client.events(date: "2026-11-30")
        #expect(list == [GalleryEvent(ev: "abc123xyz", deal: 7, title: "Teszt rendezvény", date: "2026-11-30", time: "18:00", loc: nil)])
        #expect(seen.value == "\(key)|date=2026-11-30")
    }

    @Test func badKeyIsReported() async {
        let (client, host) = makeClient()
        MockRoutes.shared.set(host) { _, _ in .success((401, Data(#"{"ok":false,"message":"Hiányzó vagy hibás gépkulcs."}"#.utf8))) }
        await #expect(throws: GalleryClient.Failure.badKey("Hiányzó vagy hibás gépkulcs.")) {
            _ = try await client.events(date: "2026-11-30")
        }
    }

    @Test func noInternetIsRecognized() async {
        let (client, host) = makeClient()
        MockRoutes.shared.set(host) { _, _ in .failure(URLError(.notConnectedToInternet)) }
        await #expect(throws: GalleryClient.Failure.offline) {
            _ = try await client.events(date: "2026-11-30")
        }
    }

    func sampleClip() throws -> (Clip, URL, URL) {
        let dir = FileManager.default.temporaryDirectory.appendingPathComponent("up-\(UUID().uuidString.prefix(6))")
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        let video = dir.appendingPathComponent("v.mp4"), poster = dir.appendingPathComponent("p.jpg")
        try Data("VIDEO-BYTES-0123".utf8).write(to: video)
        try Data("JPEG".utf8).write(to: poster)
        let clip = Clip(id: "clip0001abcd", ev: "abc123xyz", deal: 7, date: "2026-11-30", eventTitle: "Teszt", video: "v.mp4",
                        poster: "p.jpg", createdAt: Date(), duration: 12, bytes: 16)
        return (clip, video, poster)
    }

    @Test func uploadSendsKeyAndReturnsTheLink() async throws {
        let (client, host) = makeClient()
        let (clip, video, poster) = try sampleClip()
        let seen = Box("")
        MockRoutes.shared.set(host) { request, _ in
            seen.value = [request.httpMethod ?? "", request.value(forHTTPHeaderField: "X-Booth-Key") ?? "",
                          request.value(forHTTPHeaderField: "Content-Type") ?? "", request.url?.path ?? ""].joined(separator: "\n")
            return .success((200, Data(#"{"ok":true,"url":"https://x/galeria/abc123xyz/clip0001abcd"}"#.utf8)))
        }
        let url = try await client.upload(clip, video: video, poster: poster)
        #expect(url == "https://x/galeria/abc123xyz/clip0001abcd")
        let lines = seen.value.components(separatedBy: "\n")
        #expect(lines.count == 4)
        #expect(lines.first == "POST")
        #expect(lines.dropFirst().first == key)
        #expect(lines.dropFirst(2).first?.hasPrefix("multipart/form-data; boundary=") == true)
        #expect(lines.last == "/wp-json/crazy-galeria/v1/upload")
    }

    @Test func uploadBodyHasTheGalleryFields() throws {
        let (clip, video, poster) = try sampleClip()
        let body = GalleryClient.uploadBody(clip, video: video, poster: poster)
        let out = FileManager.default.temporaryDirectory.appendingPathComponent("body-\(UUID().uuidString.prefix(6))")
        defer { try? FileManager.default.removeItem(at: out) }
        try body.write(to: out)
        let s = String(decoding: try Data(contentsOf: out), as: UTF8.self)
        #expect(s.contains("name=\"ev\"\r\n\r\nabc123xyz\r\n"))
        #expect(s.contains("name=\"photo\"\r\n\r\nclip0001abcd\r\n"))
        #expect(s.contains("name=\"date\"\r\n\r\n2026-11-30\r\n"))
        #expect(s.contains("name=\"deal\"\r\n\r\n7\r\n"))
        #expect(s.contains("name=\"kind\"\r\n\r\nvideo\r\n"))
        #expect(s.contains("name=\"file\"; filename=\"clip0001abcd.mp4\"\r\nContent-Type: video/mp4\r\n\r\nVIDEO-BYTES-0123\r\n"))
        #expect(s.contains("name=\"poster\"; filename=\"clip0001abcd.jpg\"\r\nContent-Type: image/jpeg"))
        // borító nélkül nincs poster mező
        let noPoster = GalleryClient.uploadBody(clip, video: video, poster: nil)
        #expect(noPoster.parts.count == 6)
    }

    @Test func tooLargeIsRejectedForGood() async throws {
        let (client, host) = makeClient()
        let (clip, video, poster) = try sampleClip()
        MockRoutes.shared.set(host) { _, _ in .success((413, Data(#"{"ok":false,"message":"Túl nagy videó."}"#.utf8))) }
        await #expect(throws: GalleryClient.Failure.rejected("Túl nagy videó.")) {
            _ = try await client.upload(clip, video: video, poster: poster)
        }
    }
}
