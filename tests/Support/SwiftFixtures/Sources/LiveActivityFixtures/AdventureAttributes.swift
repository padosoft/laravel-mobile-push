import Foundation

// What an app declares for the Live Activity in tests/Fixtures/apns/la-start.json. The real declaration conforms to
// `ActivityAttributes` (and `ContentState` to `Hashable`); ActivityKit is not importable in a command-line package
// test, so only the `Codable` shape is declared here. A push payload is decoded into exactly these structs.
public struct AdventureAttributes: Codable, Hashable {
    public struct ContentState: Codable, Hashable {
        public var currentHealthLevel: Double
        public var eventDescription: String

        public init(currentHealthLevel: Double, eventDescription: String) {
            self.currentHealthLevel = currentHealthLevel
            self.eventDescription = eventDescription
        }
    }

    public struct Hero: Codable, Hashable {
        public var name: String
        public var avatar: String
        public var healthLevel: Int
        public var heroType: String
        public var healthRecoveryRatePerHour: Double
        public var url: String
        public var battleCode: String
        public var level: Int
        public var exp: Int
        public var bio: String
    }

    public var hero: Hero
}
