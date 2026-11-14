import Foundation

/// multipart/form-data törzs, fájlba írva (a videót darabonként másolja, nem tölti a memóriába) –
/// így a feltöltés `URLSession.upload(for:fromFile:)`-lal mehet.
public struct MultipartBody: Sendable {
    public enum Part: Sendable, Equatable {
        case field(name: String, value: String)
        case file(name: String, filename: String, contentType: String, source: URL)
    }

    public let boundary: String
    public var parts: [Part]

    public init(parts: [Part] = [], boundary: String = "CrazySpin-" + Token.make(24)) {
        self.parts = parts
        self.boundary = boundary
    }

    public var contentType: String { "multipart/form-data; boundary=\(boundary)" }

    /// A törzs kiírása `destination`-be (felülírja). Visszaadja a méretet bájtban.
    @discardableResult
    public func write(to destination: URL, chunkSize: Int = 1 << 20) throws -> Int64 {
        let fm = FileManager.default
        if fm.fileExists(atPath: destination.path) { try fm.removeItem(at: destination) }
        guard fm.createFile(atPath: destination.path, contents: nil) else {
            throw CocoaError(.fileWriteUnknown, userInfo: [NSFilePathErrorKey: destination.path])
        }
        let out = try FileHandle(forWritingTo: destination)
        defer { try? out.close() }
        var written: Int64 = 0
        func put(_ s: String) throws {
            let d = Data(s.utf8)
            try out.write(contentsOf: d)
            written += Int64(d.count)
        }
        for part in parts {
            try put("--\(boundary)\r\n")
            switch part {
            case let .field(name, value):
                try put("Content-Disposition: form-data; name=\"\(Self.quoted(name))\"\r\n\r\n")
                try put(value)
                try put("\r\n")
            case let .file(name, filename, contentType, source):
                try put("Content-Disposition: form-data; name=\"\(Self.quoted(name))\"; filename=\"\(Self.quoted(filename))\"\r\n")
                try put("Content-Type: \(contentType)\r\n\r\n")
                let input = try FileHandle(forReadingFrom: source)
                defer { try? input.close() }
                while let chunk = try input.read(upToCount: chunkSize), !chunk.isEmpty {
                    try out.write(contentsOf: chunk)
                    written += Int64(chunk.count)
                }
                try put("\r\n")
            }
        }
        try put("--\(boundary)--\r\n")
        return written
    }

    /// Idézőjel és sortörés nem kerülhet a fejlécbe.
    static func quoted(_ s: String) -> String {
        s.replacingOccurrences(of: "\"", with: "'").replacingOccurrences(of: "\r", with: " ").replacingOccurrences(of: "\n", with: " ")
    }
}
