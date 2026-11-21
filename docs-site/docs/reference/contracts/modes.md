---
title: "Execution modes and endpoints"
description: "Defines when the package may touch the network, how a live send outside production is authorized, what the three non-network-or-validation outcomes mean, and which hosts a request may reach. Source: docs/PLAN.md §4.6."
---

# Execution modes and endpoints

**Purpose.** Defines when the package may touch the network, how a live send outside production is authorized, what the three non-network-or-validation outcomes mean, and which hosts a request may reach. Source: `docs/PLAN.md` §4.6. The mode and endpoint classes under `src/Core/Policy` are being written by a parallel task; this document states the contract from the plan and does not describe their code.

## Rules

1. **Modes.** `mode = auto | live | simulate`. `auto` means `live` only when `APP_ENV=production`, otherwise `simulate`. In `simulate` no network is touched and the status is `Simulated`.
2. **Live outside production** requires both `live.allow` on the binding **and** the destination listed in `live.only_to` (test destinations only).
3. Topics, broadcast and channel management stay blocked unless explicitly authorized.
4. An inconsistency **blocks**. It never degrades to a live send.
5. The check happens before any external effect and covers the CLI, workers, failover, fallback and channel management.
6. `->validateOnly()` (FCM) is an authenticated remote call and yields `Validated`.
7. APNs has no dry run. `preview` is local and does not verify credentials. `doctor --live` is a real request, and it is described as one.
8. **Official hosts by role**, not free URLs: sending, channel management, broadcast publication (`broadcast_publish_host = standard | api-broadcast`, one of the two hosts Apple documents), and the Google token exchange. TLS and host, CA verification are always on.
9. Test endpoints can be injected only with test credentials and never in production. A message cannot name a host.

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

- Names from plan §5: `Policy\ExecutionMode`, `Policy\EndpointCatalog`, `Policy\EligibilityGuard`, `Policy\CapabilityMatrix` (the first two and the capability matrix already have files under `src/Core/Policy`, still in progress; `EligibilityGuard` has no file yet)
- In code now: `Outcome\SendStatus::Simulated`, `Validated`; `Outcome\ReasonCode::Simulated`, `Validated`, `PolicySafeMode`; `Outcome\ClassifyContext::$validateOnly`

## Worked cases

**A. Local development.**
- Situation: `APP_ENV=local`, `mode = auto`, a valid send.
- The package: `simulate`; no connection is opened.
- The caller sees: `Simulated`.

**B. Staging with live allowed, wrong target.**
- Situation: `live.allow` is on, but the destination is not in `live.only_to`.
- The package: blocked before any external effect.
- The caller sees: a blocked result. The plan says "blocca" but does not name the status (see Open points). The closest existing codes are `SendStatus::Skipped` with `ReasonCode::PolicySafeMode`.

**C. FCM dry run.**
- Situation: a message is sent with `->validateOnly()`.
- The package: an authenticated remote call; the provider validates it and does not send.
- The caller sees: `Validated`. This is not a delivery test on devices.

**D. Production config inside a sandbox command.**
- Situation: a CLI command runs with production configuration in a sandbox.
- The package: no connection is opened (plan §11 requires this).
- The caller sees: a blocked or simulated result, with no network trace.

## Plan vs code

- The `Simulated`, `Validated` and `Accepted` split matches the code's enum docblocks.
- Plan §4.6 says a blocked live send is an "incoerenza" that blocks, but does not map it to a status.

## Open points

- Status and `ReasonCode` for a live send blocked by the authorization rules (plan silent).
- Names of the test-override settings (plan says they exist and are never allowed in production, but does not name them).
- Which of the two Apple broadcast hosts is operational (plan §13, to verify by integration).
- Whether `preview` may show the destination host (plan §8 says it never shows bearer, tokens or markers; hosts are not mentioned).

---

*This page is generated from [`docs/contracts/modes.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/contracts/modes.md). To change it, edit that file in the repository, not this site.*
