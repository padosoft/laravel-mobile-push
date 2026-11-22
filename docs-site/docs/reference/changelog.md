---
title: "Changelog"
description: "All notable changes to this project are documented in this file."
---

# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [1.0.0] - 2026-11-22

First public release.

### Added

**Sending**

- Direct APNs HTTP/2 provider API: token (`.p8`, ES256), certificate (mTLS) or external signer (KMS/HSM through the `Signer` interface) authentication, all push types with their topic suffixes, priorities, interruption levels, collapse id, logical expiry, per-type payload limits.
- Direct FCM HTTP v1: targets `fid`, `token`, `topic` and `condition`; `android` or `androidV2` (mutually exclusive); `validate_only`; service-account OAuth exchange, with `google/auth` as an optional provider for Application Default Credentials, Workload Identity Federation and impersonation.
- Unified `PushMessage` with `->apns()` / `->fcm()` overlays, native `ApnsMessage` / `FcmMessage` builders, rich-content helpers, a capability matrix enforced at validation time, and opt-in truncation of title/subtitle/body only.
- `MobilePush` facade, `mobile_push()` and `mobile_push_to_token()` helpers, typed destinations, named connections, scopes for multi-tenancy.
- HTTP/2 transport on a persistent `curl_multi` pool keyed by TLS/authorization identity, with request bodies streamed so that libcurl cannot silently re-send a request.

**Outcomes**

- One explicit result per destination: `Accepted`, `Rejected`, `Failed`, `Deferred`, `OutcomeUnknown`, `Expired`, `Skipped`, `Simulated`, `Validated`, each with a `ReasonCode`, the provider's raw reason and a hint.
- An unknown outcome is never treated as a failure: no automatic retry of non-replaceable pushes, no invalidation, no fallback.
- A single retry planner with one budget per destination, message-counted rate limits, and separate breakers for authentication, quota and destination quality. When the cache behind them is unreachable they fail open; when it is contended the destinations are deferred; a send never throws for it.
- Streaming `SendReport` with bounded memory.

**Safe mode**

- `mode = auto | live | simulate`: outside production nothing reaches the network unless a connection allows it and the destination is on its allow-list. Official provider hosts only.

**Delivery profiles**

- Light profile (default, no tables, best effort) and durable profile (effects and deliveries ledger, outbox written in the caller's transaction, leases, relay job and sweeper, idempotency keys with a deduplication window, attempts marked per transport window, follow-up relays folded when many effects are deferred at once).
- Migrations are published by `mobile-push:install` (or the module tags) and are idempotent; `migrations.autoload` makes enabled modules register them with `migrate` instead.

**Live Activities**

- Push-to-start, update and end with typed tokens, serialized transitions, local expiry, high-priority budget; broadcast channels (create, list, delete, sync, publish).

**Optional modules** (all off by default)

- Device registry with quarantine, invalidation, generations and an explicit operator probe for the APNs environment.
- Eligibility checks right before the network; frequency cap and badge counter per logical effect.
- Notification channels (`push`, `apns`, `fcm`) with strict fallback rules to another channel.
- Client-reported receipts (received / opened) with signed markers.
- Campaigns on the durable profile: resumable planner, pause / resume / cancel, reconciliation.
- Send log sinks (`log`, `database`, `otel`), health checks and metrics, credential expiry alerts, Pulse card, Telescope entries.
- Privacy tooling: encrypted tokens with an HMAC lookup index, redaction before every sink, `forget` and `export`.

**Tooling**

- 38 Artisan commands under `mobile-push:*` (install, doctor, send, preview, explain, simulator, connections, circuit, relay, sweep, prune commands, health, credentials check, forget, export, environment probe, topic subscriptions, Live Activities, channels, campaigns). See `docs/commands.md`.
- The package schedules its own housekeeping (sweeper, alert evaluation, Live Activity flush, campaign sweep, daily prune commands and credential check), each behind a config switch; the application only runs Laravel's scheduler.
- FCM topic subscriptions from code (`MobilePush::topicSubscriptions()`) and from the command line.
- Testing fakes: `MobilePush::fake()` (intent) and `MobilePush::fakeTransport()` (bytes on the wire).
- `docs/configuration.md` generated from the configuration schema; documentation examples executed by the test suite.
- Reproducible benchmarks in `benchmarks/`, and a runtime compatibility page (`docs/runtimes.md`) that separates what was measured (queue worker loop, forks, many tenants, cache coordination across processes; in CI: Redis, Horizon, SQS and DynamoDB emulators, memcached, beanstalkd, Octane on RoadRunner, Swoole, OpenSwoole and FrankenPHP) from what was not run (Vapor).
- Documentation site sources in `docs-site/` (docmd).
- `transport.reuse` (`auto`, `on`, `off`): connection reuse between sends can be switched off, and is switched off by itself for a send that starts while Swoole or OpenSwoole hooks curl.
- `devices.fcm_prefer` (`token`, `fid`): where a device row that holds both an FCM token and an installation id is sent.
- `connections.*.key_environments`: declares an APNs key restricted to one environment, so that the environment probe refuses it before any request.
- Both delivery profiles enforce `queue.chunk_deadline`; the effect id of a queued send is on the per-result and batch events and in the send log.

---

*This page is generated from [`CHANGELOG.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/CHANGELOG.md). To change it, edit that file in the repository, not this site.*
