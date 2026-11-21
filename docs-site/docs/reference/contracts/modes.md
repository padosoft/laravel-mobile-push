---
title: "Execution modes and endpoints"
description: "Defines when the package may touch the network, how a live send outside production is authorized, what the three non-network-or-validation outcomes mean, and which hosts a request may reach."
---

# Execution modes and endpoints

**Purpose.** Defines when the package may touch the network, how a live send outside production is authorized, what the three non-network-or-validation outcomes mean, and which hosts a request may reach. Source: `docs/PLAN.md` §4.6 and the code in `src/Core/Policy`. The user-facing description is `docs/safe-mode.md`.

## Rules

1. **Modes.** `mode = auto | live | simulate`. `auto` means `live` only when `APP_ENV=production`, otherwise `simulate`. In `simulate` no network is touched and the status is `Simulated`.
2. **Live outside production** requires both `live.allow` on the binding **and** the destination listed in `live.only_to` (test destinations only; the tokens are converted to keyed indexes at load time and never stored raw).
3. Topics and conditions (`live.allow_topics`), broadcast channels (`live.allow_broadcast`) and channel management (`live.allow_channel_management`) stay blocked outside production unless explicitly authorized on the binding.
4. An inconsistency **blocks**. It never degrades to a live send.
5. The check happens before any external effect and covers the CLI, workers, failover, fallback and channel management.
6. `->validateOnly()` (FCM) is an authenticated remote call and yields `Validated`.
7. APNs has no dry run. `preview` is local and does not verify credentials. `doctor --live` is a real request, and it is described as one.
8. **Official hosts by role** (`EndpointRole`, resolved by `EndpointCatalog`), not free URLs: sending, channel management, broadcast publication (connection key `broadcast_host = standard | api-broadcast`, `ApnsBroadcastHost`), and the Google token exchange. TLS and host, CA verification are always on.
9. Test endpoints (`EndpointOverride`) are accepted only when the caller affirms throw-away test credentials **and** the application environment is not production; origins are `https://host[:port]` with no path. A message cannot name a host.
10. **Every operation is asked first**: `SafeModeGuard::decide()` takes an `OperationKind` (`Send`, `Validate`, `ChannelManagement`, `BroadcastPublish`, `TopicSubscription`, `Probe`) and returns a `SafeModeDecision`. The CLI commands, workers, failover, the environment probe and channel management all go through it.
11. **A blocked live send is `Skipped` with `ReasonCode::PolicySafeMode`**; simulation is `Simulated` (decided at the orchestrator's resolutions, see `README.md`).

## Status meaning

| Status | Produced when | What it does NOT mean |
|---|---|---|
| `Simulated` | Mode is `simulate`; no network touched | Not sent, not validated |
| `Validated` | FCM `validate_only` answered by the provider | Not sent to the device |
| `Accepted` | Live send and provider accepted | Not delivered, not seen |

## Mode table

| `mode` | `APP_ENV` | Network | Result for a valid message |
|---|---|---|---|
| `auto` | `production` | Yes (live) | Provider result |
| `auto` | any other | No | `Simulated` |
| `live` | any | Yes, subject to the authorization rules | Provider result, or blocked |
| `simulate` | any | No | `Simulated` |

## Types

- `Policy\ExecutionMode`, `ModeResolver` (mode and `APP_ENV` to live or simulate), `SafeModeGuard`, `SafeModeDecision`, `OperationKind`, `LiveSettings` (per-binding `allow`, `only_to`, `allow_topics`, `allow_broadcast`, `allow_channel_management`)
- `Policy\EndpointCatalog`, `EndpointRole`, `EndpointOverride`, `ApnsBroadcastHost`, `CapabilityMatrix`
- `Outcome\SendStatus::Simulated`, `Validated`; `ReasonCode::Simulated`, `Validated`, `PolicySafeMode`; `ClassifyContext::$validateOnly`
- Eligibility checks that run before the network live in the Laravel layer (`DeviceEligibility`, the consent gates) and in `Core\Driver\DestinationGate`; there is no `Policy\EligibilityGuard` class.

## Worked cases

**A. Local development.**
- Situation: `APP_ENV=local`, `mode = auto`, a valid send.
- The package: `simulate`; no connection is opened.
- The caller sees: `Simulated`.

**B. Staging with live allowed, wrong target.**
- Situation: `live.allow` is on, but the destination is not in `live.only_to`.
- The package: blocked before any external effect.
- The caller sees: `SendStatus::Skipped` with `ReasonCode::PolicySafeMode` and a hint that names the missing setting.

**C. FCM dry run.**
- Situation: a message is sent with `->validateOnly()`.
- The package: an authenticated remote call; the provider validates it and does not send.
- The caller sees: `Validated`. This is not a delivery test on devices.

**D. Production config inside a sandbox command.**
- Situation: a CLI command runs with production configuration in a sandbox.
- The package: no connection is opened (plan §11 requires this).
- The caller sees: a `Skipped` (`PolicySafeMode`) or `Simulated` result, with no network trace.

## Plan vs code

- The `Simulated`, `Validated` and `Accepted` split matches the code's enum docblocks.
- Plan §4.6 does not map a blocked live send to a status; the code and the orchestrator's resolutions do (rule 11).
- The plan did not name the test-override settings: they are `EndpointOverride` (used by the test seams of the package), with no config key on purpose.

## Open points

- Which of the two Apple broadcast hosts Apple operates for a given app (`broadcast_host`): both are documented, the default is `standard`, and neither has been exercised against Apple.
- Whether `preview` may show the destination host: the command prints the compiled message (headers without authorization, the body, the byte count); it does not print a host. Whether it ever should is not decided.
- Everything that touches a real provider (`doctor --live`, FCM `validate_only`, channel management) is unverified against Apple and Google.

---

*This page is generated from [`docs/contracts/modes.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/contracts/modes.md). To change it, edit that file in the repository, not this site.*
