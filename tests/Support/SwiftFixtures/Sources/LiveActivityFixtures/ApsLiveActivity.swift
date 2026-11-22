import Foundation

/// The `aps` dictionary of a Live Activity push, as the fixtures spell it. `ContentState` and `Attributes` are
/// generic so that the same model serves every fixture. Keys that the package emits only sometimes are optional.
public struct ApsLiveActivity<ContentState: Decodable, Attributes: Decodable>: Decodable {
    public var timestamp: Int
    public var event: String
    public var contentState: ContentState
    public var attributesType: String?
    public var attributes: Attributes?
    public var staleDate: Int?
    public var dismissalDate: Int?
    public var relevanceScore: Double?

    enum CodingKeys: String, CodingKey {
        case timestamp, event, attributes
        case contentState = "content-state"
        case attributesType = "attributes-type"
        case staleDate = "stale-date"
        case dismissalDate = "dismissal-date"
        case relevanceScore = "relevance-score"
    }
}

/// Used for fixtures that carry no `attributes`.
public struct NoAttributes: Decodable {}

/// `{ "payload": { "aps": ... } }`, the shape of the fixture files (the other top-level keys are ignored).
public struct LiveActivityFixture<ContentState: Decodable, Attributes: Decodable>: Decodable {
    public struct Payload: Decodable {
        public var aps: ApsLiveActivity<ContentState, Attributes>
    }

    public var payload: Payload
}
