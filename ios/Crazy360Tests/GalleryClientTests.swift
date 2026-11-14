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
        let body = request.httpBody ?? Self.read(request.httpBodyStream)
        switch handler(request, body) {
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

    static func read(_ stream: InputStream?) -> Data {
        guard let stream else { return Data() }
        stream.open()
        defer { stream.close() }
        var data = Data()
        var buffer = [UInt8](repeating: 0, count: 64 * 1024)
        while stream.hasBytesAvailable {
            let n = stream.read(&buffer, maxLength: buffer.count)
            if n <= 0 { break }
            data.append(buffer, count: n)
        }
        return data
    }
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

    @Test func uploadSendsTheMultipartForm() async throws {
        let (client, host) = makeClient()
        let (clip, video, poster) = try sampleClip()
        let seen = Box("")
        MockRoutes.shared.set(host) { request, body in
            let text = String(decoding: body, as: UTF8.self)
            seen.value = [request.httpMethod ?? "", request.value(forHTTPHeaderField: "X-Booth-Key") ?? "",
                          request.value(forHTTPHeaderField: "Content-Type") ?? "", text].joined(separator: "\n")
            return .success((200, Data(#"{"ok":true,"url":"https://x/galeria/abc123xyz/clip0001abcd"}"#.utf8)))
        }
        let url = try await client.upload(clip, video: video, poster: poster)
        #expect(url == "https://x/galeria/abc123xyz/clip0001abcd")
        let s = seen.value
        #expect(s.hasPrefix("POST\n\(key)\nmultipart/form-data; boundary="))
        // a törzs a feltöltési módtól függően elérhető-e a mocknak: ha igen, a mezőket is ellenőrizzük
        if s.contains("Content-Disposition") {
            #expect(s.contains("name=\"photo\"\r\n\r\nclip0001abcd"))
            #expect(s.contains("name=\"kind\"\r\n\r\nvideo"))
            #expect(s.contains("name=\"ev\"\r\n\r\nabc123xyz"))
            #expect(s.contains("filename=\"clip0001abcd.mp4\""))
            #expect(s.contains("VIDEO-BYTES-0123"))
            #expect(s.contains("name=\"poster\"; filename=\"clip0001abcd.jpg\""))
        }
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
