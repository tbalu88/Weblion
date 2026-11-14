import Foundation
import Testing
@testable import SpinKit

struct MultipartTests {
    func tempURL(_ name: String) -> URL {
        FileManager.default.temporaryDirectory.appendingPathComponent("spinkit-\(Token.make(10))-\(name)")
    }

    @Test func writesExactBodyInChunks() throws {
        let src = tempURL("v.mp4")
        let payload = Data((0..<1000).map { UInt8($0 % 251) })
        try payload.write(to: src)
        defer { try? FileManager.default.removeItem(at: src) }
        let body = MultipartBody(parts: [
            .field(name: "ev", value: "abc123"),
            .field(name: "kind", value: "video"),
            .file(name: "file", filename: "xyz.mp4", contentType: "video/mp4", source: src),
        ], boundary: "B0UND")
        let out = tempURL("body")
        defer { try? FileManager.default.removeItem(at: out) }
        let n = try body.write(to: out, chunkSize: 7)
        let got = try Data(contentsOf: out)
        var want = Data()
        want.append(Data("--B0UND\r\nContent-Disposition: form-data; name=\"ev\"\r\n\r\nabc123\r\n".utf8))
        want.append(Data("--B0UND\r\nContent-Disposition: form-data; name=\"kind\"\r\n\r\nvideo\r\n".utf8))
        want.append(Data("--B0UND\r\nContent-Disposition: form-data; name=\"file\"; filename=\"xyz.mp4\"\r\nContent-Type: video/mp4\r\n\r\n".utf8))
        want.append(payload)
        want.append(Data("\r\n--B0UND--\r\n".utf8))
        #expect(got == want)
        #expect(n == Int64(want.count))
        #expect(body.contentType == "multipart/form-data; boundary=B0UND")
    }

    @Test func overwritesAndSanitizesHeaders() throws {
        let out = tempURL("body2")
        defer { try? FileManager.default.removeItem(at: out) }
        try Data("régi tartalom, ami hosszabb".utf8).write(to: out)
        let body = MultipartBody(parts: [.field(name: "a\"b\nc", value: "érték")], boundary: "X")
        try body.write(to: out)
        let s = String(decoding: try Data(contentsOf: out), as: UTF8.self)
        #expect(s == "--X\r\nContent-Disposition: form-data; name=\"a'b c\"\r\n\r\nérték\r\n--X--\r\n")
    }

    @Test func missingSourceThrows() {
        let body = MultipartBody(parts: [.file(name: "file", filename: "x.mp4", contentType: "video/mp4", source: tempURL("nincs"))])
        let out = tempURL("body3")
        defer { try? FileManager.default.removeItem(at: out) }
        #expect(throws: (any Error).self) { try body.write(to: out) }
    }

    @Test func defaultBoundaryIsUnique() {
        #expect(MultipartBody().boundary != MultipartBody().boundary)
    }
}
