# Documentation

`padosoft/laravel-mobile-push` sends push notifications from Laravel directly to Apple APNs (HTTP/2, Live Activities and broadcast channels included) and to Google FCM HTTP v1. Start with the [README](../README.md), then [getting started](getting-started.md).

## Start here

- [Getting started](getting-started.md): install, credentials, `.env`, `doctor`, the first simulated send, going live, and a "my push did not arrive" checklist.
- [APNs credentials](credentials-apns.md): `.p8` keys (team-scoped, topic-specific, per environment), certificates, secret sources, rotation, checks.
- [FCM credentials](credentials-fcm.md): service-account JSON, least privilege, Application Default Credentials, rotation, why `token_uri` is ignored.
- [Configuration reference](configuration.md): every key of `config/mobile-push.php` with its env variable, default and allowed values (generated).

## Sending

- [Sending](sending.md): every way to address a push (`to()`, `toToken()`, `toTokens()`, `toTopic()`, `toCondition()`), connections, scopes, options, the streaming report, helpers, `mobile-push:send` and its exit codes.
- [Messages](messages.md): the unified `PushMessage` field by field on iOS and Android, overlays, native APNs/FCM messages, size limits, truncation, expiry, the capability matrix.
- [Outcomes](outcomes.md): every status and reason code, and what "accepted" and "unknown outcome" really mean.
- [Notifications and fallback](notifications-and-fallback.md): the `push`, `apns` and `fcm` notification channels, and falling back to another channel.
- [Live Activities](live-activities.md): start, update, end, push-to-start tokens, broadcast channels.
- [Topics](topics.md): FCM topics and topic subscriptions.
- [Campaigns](campaigns.md): large sends with audiences, slices, pause/resume/cancel and progress.

## Reliability

- [Delivery profiles](delivery-profiles.md): light (best effort) versus durable (ledger + outbox), and what each one guarantees.
- [Retries and limits](retries-and-limits.md): the retry budget, provider delays, rate limits and circuit breakers.
- [Queues and Horizon](queues-and-horizon.md): queue names, workers, the timeout chain, Horizon supervisors.
- [HTTP/2 transport](transport.md): how the transport works, libcurl's automatic re-sends and why bodies are streamed, the measurements behind each choice.

## Operations

- [Commands](commands.md): every `mobile-push:*` Artisan command.
- [Safe mode](safe-mode.md): simulation by default outside production, `live.allow`, `live.only_to`, topic/broadcast flags.
- [Multi-tenancy](multi-tenancy.md): scopes, runtime connections, resolvers, binding identity and generations.
- [Devices](devices.md): the device registry, quarantine and invalidation, environment probe, pruning.
- [Policies](policies.md): consent hooks, frequency cap, server-side badge counter.
- [Receipts](receipts.md): client-reported "received" / "opened", markers and funnels.
- [Observability](observability.md): events, log sinks, metrics, Prometheus, health, Pulse, Telescope, alerts.
- [Security and privacy](security-and-privacy.md): encryption at rest, HMAC indexes, redaction, `forget` / `export`.
- [Troubleshooting](troubleshooting.md): symptoms and reason codes, with causes and remedies.

## Testing and choosing

- [Testing](testing.md): `MobilePush::fake()` for intent, `MobilePush::fakeTransport()` for bytes, every assertion and scripting method.
- [Comparison](comparison.md): capabilities of pushok, the Laravel notification channels, kreait/firebase-php and ApnsPHP, pinned to their source.
- [Migrating from pushok](migrating-from-pushok.md).
- [Migrating from laravel-notification-channels/apn](migrating-from-laravel-notification-channels-apn.md).

## Contracts (for contributors)

- [Contracts](contracts/README.md): outcomes, identity, ledger, classification, retry, modes, messages.
