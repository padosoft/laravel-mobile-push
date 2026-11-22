# SwiftFixtures

A small Swift package that decodes the Live Activity fixtures in `tests/Fixtures/apns/la-*.json` into the `Codable`
structs an app would declare (`AdventureAttributes`, its `ContentState`, and a model of the `aps` dictionary). It makes
sure that the JSON shape the package sends matches what a Swift `JSONDecoder` reads. The fixtures are read in place
(path resolved with `#filePath`); nothing is copied.

Run on macOS with the Xcode toolchain:

```bash
cd tests/Support/SwiftFixtures && swift test
```

What it does not prove: it does not import ActivityKit, so the `ActivityAttributes` conformance and ActivityKit's own
decoding are not exercised; no device or simulator is involved; no push is delivered by APNs. The fixtures do not
contain `stale-date` or `dismissal-date`, so those keys are only decoded as optional integers. It has not been compiled
by the agent that wrote it (no Swift toolchain was available there).
