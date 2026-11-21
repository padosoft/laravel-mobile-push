# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

Nothing in this list has been exercised against the real APNs or FCM services yet: everything is verified against
fixtures, a local HTTP/2 mock server and the published API descriptions. See "Project status" in the README for the
list of unverified points.

### Added

**Sending**

- Direct APNs HTTP/2 provider API: token (`.p8`, ES256) and certificate (mTLS) authentication, all push types with
  their topic suffixes, priorities, interruption levels, collapse id, logical expiry, per-type payload limits.
- Direct FCM HTTP v1: targets `fid`, `token`, `topic` and `condition`; `android` or `androidV2` (mutually exclusive);
  `validate_only`; service-account OAuth exchange, with `google/auth` as an optional provider for Application Default
  Credentials, Workload Identity Federation and impersonation.
- Unified `PushMessage` with `->apns()` / `->fcm()` overlays, native `ApnsMessage` / `FcmMessage` builders, rich-content
  helpers, a capability matrix enforced at validation time, and opt-in truncation of title/subtitle/body only.
- `MobilePush` facade, `mobile_push()` and `mobile_push_to_token()` helpers, typed destinations, named connections,
  scopes for multi-tenancy.
- HTTP/2 transport on a persistent `curl_multi` pool keyed by TLS/authorization identity, with request bodies streamed
  so that libcurl cannot silently re-send a request.

**Outcomes**

- One explicit result per destination: `Accepted`, `Rejected`, `Failed`, `Deferred`, `OutcomeUnknown`, `Expired`,
  `Skipped`, `Simulated`, `Validated`, each with a `ReasonCode`, the provider's raw reason and a hint.
- An unknown outcome is never treated as a failure: no automatic retry of non-replaceable pushes, no invalidation, no
  fallback.
- A single retry planner with one budget per destination, message-counted rate limits, and separate breakers for
  authentication, quota and destination quality.
- Streaming `SendReport` with bounded memory.

**Safe mode**

- `mode = auto | live | simulate`: outside production nothing reaches the network unless a connection allows it and
  the destination is on its allow-list. Official provider hosts only.

**Delivery profiles**

- Light profile (default, no tables, best effort) and durable profile (effects and deliveries ledger, outbox written in
  the caller's transaction, leases, relay job and sweeper, idempotency keys).

**Live Activities**

- Push-to-start, update and end with typed tokens, serialized transitions, local expiry, high-priority budget;
  broadcast channels (create, list, delete, sync, publish).

**Optional modules** (all off by default)

- Device registry with quarantine, invalidation, generations and an explicit operator probe for the APNs environment.
- Eligibility checks right before the network; frequency cap and badge counter per logical effect.
- Notification channels (`push`, `apns`, `fcm`) with strict fallback rules to another channel.
- Client-reported receipts (received / opened) with signed markers.
- Campaigns on the durable profile: resumable planner, pause / resume / cancel, reconciliation.
- Send log sinks (`log`, `database`, `otel`), health checks and metrics, credential expiry alerts, Pulse card,
  Telescope entries.
- Privacy tooling: encrypted tokens with an HMAC lookup index, redaction before every sink, `forget` and `export`.

**Tooling**

- Artisan commands under `mobile-push:*` (install, doctor, send, preview, explain, simulator, connections, circuit,
  relay, sweep, prune commands, health, credentials check, forget, export, environment probe, topic subscriptions,
  Live Activities, channels, campaigns). See `docs/commands.md`.
- Testing fakes: `MobilePush::fake()` (intent) and `MobilePush::fakeTransport()` (bytes on the wire).
- `docs/configuration.md` generated from the configuration schema; documentation examples executed by the test suite.
- Reproducible benchmarks in `benchmarks/`.
- Documentation site sources in `docs-site/` (docmd).
