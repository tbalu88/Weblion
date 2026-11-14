import Foundation

/// Kis JSON-fájlok mentése / betöltése az Application Supportba (atomikus írás).
nonisolated enum JSONStore {
    static func url(_ name: String) -> URL { Paths.support.appendingPathComponent(name) }

    static func load<T: Decodable>(_ type: T.Type, from name: String) -> T? {
        guard let data = try? Data(contentsOf: url(name)) else { return nil }
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601
        return try? decoder.decode(T.self, from: data)
    }

    static func save<T: Encodable>(_ value: T, to name: String) {
        let encoder = JSONEncoder()
        encoder.dateEncodingStrategy = .iso8601
        encoder.outputFormatting = [.sortedKeys]
        guard let data = try? encoder.encode(value) else { return }
        try? FileManager.default.createDirectory(at: Paths.support, withIntermediateDirectories: true)
        try? data.write(to: url(name), options: [.atomic])
    }
}
