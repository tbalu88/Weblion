// swift-tools-version: 6.0
// SpinKit – a Crazy 360 app tiszta logikája (csak Foundation): sebesség-ív és képkocka-terv, oda-vissza,
// galéria-API, multipart-törzs, feltöltési sor, beállítások. Önállóan tesztelhető: `swift test`.
import PackageDescription

let package = Package(
    name: "SpinKit",
    platforms: [.iOS(.v17), .macOS(.v14)],
    products: [.library(name: "SpinKit", targets: ["SpinKit"])],
    targets: [
        .target(name: "SpinKit"),
        .testTarget(name: "SpinKitTests", dependencies: ["SpinKit"]),
    ]
)
