import Foundation

/// Hol mi van a telefonon.
/// A Dokumentumok mappa a Fájlok appban is látszik („Crazy 360”): onnan menthetők ki a videók, és oda tehetők a keretek, zenék.
nonisolated enum Paths {
    static var documents: URL {
        FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0]
    }

    /// A kész videók és borítóképeik.
    static var videos: URL { documents.appendingPathComponent("Videók", isDirectory: true) }
    /// Keretek (PNG, átlátszó ablakkal).
    static var frames: URL { documents.appendingPathComponent("Keretek", isDirectory: true) }
    /// Zenék.
    static var music: URL { documents.appendingPathComponent("Zenék", isDirectory: true) }
    /// Belső állapot (beállítások, videólista) – a Fájlok appban nem látszik.
    static var support: URL {
        FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("Crazy360", isDirectory: true)
    }
    /// Nyers felvételek a feldolgozásig.
    static var recordings: URL {
        FileManager.default.temporaryDirectory.appendingPathComponent("Felvételek", isDirectory: true)
    }
    /// Feltöltési törzsek (ideiglenes).
    static var uploads: URL {
        FileManager.default.temporaryDirectory.appendingPathComponent("Feltöltés", isDirectory: true)
    }

    static func prepare() {
        for dir in [videos, frames, music, support, recordings, uploads] {
            try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        }
    }

    static func video(_ id: String) -> URL { videos.appendingPathComponent("\(id).mp4") }
    static func poster(_ id: String) -> URL { videos.appendingPathComponent("\(id).jpg") }
}
