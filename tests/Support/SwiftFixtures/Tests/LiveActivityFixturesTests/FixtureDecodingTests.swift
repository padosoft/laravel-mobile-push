import Foundation
import XCTest
@testable import LiveActivityFixtures

final class FixtureDecodingTests: XCTestCase {
    typealias State = AdventureAttributes.ContentState

    /// tests/Fixtures/apns, resolved from this file: SwiftFixtures/Tests/<target>/<file> -> five levels up is tests/.
    private static let fixtures = URL(fileURLWithPath: #filePath)
        .deletingLastPathComponent() // <target>
        .deletingLastPathComponent() // Tests
        .deletingLastPathComponent() // SwiftFixtures
        .deletingLastPathComponent() // Support
        .deletingLastPathComponent() // tests
        .appendingPathComponent("Fixtures/apns")

    private func data(_ name: String) throws -> Data {
        try Data(contentsOf: Self.fixtures.appendingPathComponent("\(name).json"))
    }

    /// Decoder configuration for a push payload: default key strategy (the keys must match the property names
    /// exactly) and the default date strategy (`.deferredToDate`, seconds since 2001-01-01). None of the
    /// `content-state` objects in these fixtures contains a date, so no date is decoded here; `timestamp`,
    /// `stale-date` and `dismissal-date` are plain Unix-second integers that APNs reads, not ActivityKit's decoder.
    /// If the package ever puts a date inside a content-state, this test must be extended with the strategy the app
    /// declares for it.
    private func decoder() -> JSONDecoder { JSONDecoder() }

    private func decode<A: Decodable>(_ name: String, as: A.Type) throws -> ApsLiveActivity<State, A> {
        try decoder().decode(LiveActivityFixture<State, A>.self, from: data(name)).payload.aps
    }

    func testStartDecodesStateAndAttributes() throws {
        let aps = try decode("la-start", as: AdventureAttributes.self)
        XCTAssertEqual(aps.contentState, State(currentHealthLevel: 100, eventDescription: "Adventure has begun!"))
        XCTAssertEqual(aps.attributesType, "AdventureAttributes")
        XCTAssertEqual(aps.attributesType, String(describing: AdventureAttributes.self))
        let hero = try XCTUnwrap(aps.attributes).hero
        XCTAssertEqual(hero.name, "Power Panda")
        XCTAssertEqual(hero.avatar, "U+1F43C")
        XCTAssertEqual(hero.healthLevel, 100)
        XCTAssertEqual(hero.heroType, "Forest Dweller")
        XCTAssertEqual(hero.healthRecoveryRatePerHour, 0.25)
        XCTAssertEqual(hero.url, "game:///panda")
        XCTAssertEqual(hero.battleCode, "game:///panda/battle")
        XCTAssertEqual(hero.level, 3)
        XCTAssertEqual(hero.exp, 600)
        XCTAssertEqual(hero.bio, "Power Panda loves eating bamboo shoots and leaves.")
    }

    func testUpdateWithAlertDecodesState() throws {
        let aps = try decode("la-update-alert", as: NoAttributes.self)
        XCTAssertEqual(aps.contentState, State(currentHealthLevel: 0.0, eventDescription: "Power Panda has been knocked down!"))
        XCTAssertNil(aps.attributes)
    }

    func testRelevanceUpdateDecodesState() throws {
        let aps = try decode("la-relevance-update", as: NoAttributes.self)
        XCTAssertEqual(aps.contentState, State(currentHealthLevel: 0.941, eventDescription: "Power Panda found a sword!"))
    }

    func testBroadcastUpdateDecodesState() throws {
        let aps = try decode("la-broadcast-update", as: NoAttributes.self)
        XCTAssertEqual(aps.contentState, State(currentHealthLevel: 0.0, eventDescription: "Power Panda has been knocked down!"))
    }

    /// JSON types as Apple documents them, checked on the raw JSON (JSONSerialization keeps integer and floating
    /// point apart enough to tell NSNumber kinds: a date must not carry a fractional part or be a string).
    func testApsKeysHaveTheDocumentedJsonTypes() throws {
        let documentedEvents: Set<String> = ["start", "update", "end"]
        for name in ["la-start", "la-update-alert", "la-relevance-update", "la-broadcast-update"] {
            let root = try XCTUnwrap(JSONSerialization.jsonObject(with: data(name)) as? [String: Any], name)
            let payload = try XCTUnwrap(root["payload"] as? [String: Any], name)
            let aps = try XCTUnwrap(payload["aps"] as? [String: Any], name)

            let event = try XCTUnwrap(aps["event"] as? String, "\(name): event is a string")
            XCTAssertTrue(documentedEvents.contains(event), "\(name): event \(event)")

            for key in ["timestamp", "stale-date", "dismissal-date"] {
                guard let value = aps[key] else { continue }
                let number = try XCTUnwrap(value as? NSNumber, "\(name): \(key) is a number")
                XCTAssertFalse(value is String, "\(name): \(key) is not a string")
                XCTAssertEqual(number.doubleValue, number.doubleValue.rounded(), "\(name): \(key) is whole seconds")
            }
            XCTAssertNotNil(aps["timestamp"], "\(name): timestamp present")

            if let score = aps["relevance-score"] {
                XCTAssertTrue(score is NSNumber, "\(name): relevance-score is a number")
                XCTAssertFalse(score is String, "\(name): relevance-score is not a string")
            }
        }
        // The fixtures do not cover stale-date and dismissal-date; relevance-score is covered by la-relevance-update.
        let relevance = try decode("la-relevance-update", as: NoAttributes.self)
        XCTAssertEqual(relevance.relevanceScore, 100)
        XCTAssertEqual(relevance.timestamp, 1685952000)
        XCTAssertEqual(relevance.event, "update")
    }

    /// Negative control: a renamed key on the PHP side must be noticed.
    func testRenamedContentStateKeyFailsToDecode() throws {
        let renamed = Data(#"{"current_health_level": 1.0, "eventDescription": "x"}"#.utf8)
        XCTAssertThrowsError(try decoder().decode(State.self, from: renamed)) { error in
            guard case DecodingError.keyNotFound(let key, _) = error else {
                return XCTFail("expected keyNotFound, got \(error)")
            }
            XCTAssertEqual(key.stringValue, "currentHealthLevel")
        }
        let valid = Data(#"{"currentHealthLevel": 1.0, "eventDescription": "x"}"#.utf8)
        XCTAssertNoThrow(try decoder().decode(State.self, from: valid))
    }
}
