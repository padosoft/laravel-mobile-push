// swift-tools-version:5.9
import PackageDescription

let package = Package(
    name: "SwiftFixtures",
    platforms: [.macOS(.v12)],
    products: [
        .library(name: "LiveActivityFixtures", targets: ["LiveActivityFixtures"]),
    ],
    targets: [
        .target(name: "LiveActivityFixtures"),
        .testTarget(name: "LiveActivityFixturesTests", dependencies: ["LiveActivityFixtures"]),
    ]
)
