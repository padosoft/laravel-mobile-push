---
title: "Messages, limits and capabilities"
description: "Defines the unified PushMessage, how platform overlays are applied, the size limits and what truncation may touch, logical expiry, the capability matrix rule, the difference between actionCategory and policy, and the…"
---

# Messages, limits and capabilities

**Purpose.** Defines the unified `PushMessage`, how platform overlays are applied, the size limits and what truncation may touch, logical expiry, the capability matrix rule, the difference between `actionCategory` and `policy`, and the FCM target rules. Sources: `docs/PLAN.md` §6.3 and §6.4, and `src/Core/Message`, `src/Core/Apns/Message/ApnsMessage.php`, `src/Core/Fcm/Message/FcmMessage.php` (the overlays the unified message uses).

## Rules

1. `PushMessage` is one small unified message. Platform specifics go through `->apns(fn)` and `->fcm(fn)`. Each callback runs immediately against an overlay returned by `ApnsMessage::overlay()` or `FcmMessage::overlay()`, which must be empty and serializable. Overlays are never stored as closures.
2. **Limits** apply per driver and target, after overlays, encoding and the receipt marker (`MessageCompiler` docblock):
   - APNs: 4096 bytes; VoIP and broadcast: 5120 bytes.
   - FCM: 4096 bytes; topic or condition: 2048 bytes, measured on the FCM payload, not on the REST envelope (plan §6.3 and §6.4).
3. **Reject by default.** Over-limit payloads throw `PayloadTooLargeException` unless `->truncate()` is set.
4. **Truncation touches only title, subtitle and body.** Never IDs, URLs, custom data or Live Activity state (`TextFitter` docblock, plan §6.3).
5. Truncation keeps whole grapheme clusters and includes the ellipsis in the byte count (`Truncator`). Both grapheme paths exist: `ext-intl` (`grapheme_extract`) when present and the PCRE `\X` path otherwise; they are tested to agree.
6. **Validation**: UTF-8 is checked; unrepresentable values throw `InvalidMessageException`; reserved keys and conflicts are rejected (plan §6.3).
7. **Logical expiry.** `->expiresAt()` (absolute) or `->ttl()` (relative). The instant is pinned when the effect is created. Each attempt recomputes the APNs expiration and the FCM remaining TTL from `now`, without extending the expiry (`CompileContext` docblock, plan §6.3).
8. **Capability matrix** (`Policy\CapabilityMatrix`, one `CapabilityCell` per capability and platform). Each capability is native, requires app-side code, or unavailable. A missing capability is a validation error (`UnsupportedCapabilityException`), never a silent success. `->lenient()` drops it and records a warning (`CompiledMessage::$warnings`). A few cells (Android badge, group, time-sensitive, iOS deep link conventions, Android broadcast) are marked "unverified" in the class docblock.
9. **`actionCategory` is not `policy`.** `actionCategory` is the app-side action set (iOS category, Android `click_action`). `policy` is the sending bucket used for consent and caps (e.g. `marketing`); it is never sent to the device.
10. **FCM target.** Exactly one target per request: `fid`, `token`, `topic` or `condition`. A `token` is deprecated but supported; its origin type is kept and never converted by heuristic. An installation with both FID and token receives one send per effect (`fcm.prefer = fid | token`).
11. **`android` and `androidV2` are mutually exclusive.** `FcmMessage::android()` and `androidV2()` throw `InvalidMessageException` when the other is already set.
12. Topic subscriptions (`topicSubscriptions`: create, get, list, patch, delete, per installation) are separate from publishing. There is no bulk operation and no use of the legacy IID API (plan §6.4).

## Limits

| Target | Limit | Source |
|---|---|---|
| APNs | 4096 bytes | Plan §6.3 |
| APNs VoIP and broadcast | 5120 bytes | Plan §6.3 |
| FCM message | 4096 bytes | Plan §6.3 |
| FCM topic or condition | 2048 bytes | Plan §6.3 |

The code constants are `Apns\ApnsPayloadLimits` (`STANDARD` 4096, `VOIP` and `BROADCAST` 5120) and `Fcm\FcmPayloadLimits` (4096, and 2048 for topic and condition). **Unverified:** Google publishes the numbers but, as of the Discovery revision checked (20261112), not an exact measurement rule for the FCM limit; the package measures the FCM payload it builds, not the REST envelope. `CompiledMessage::$payloadBytes` is the counted size, which is not necessarily `strlen($body)`.

## Types

- `Message\Message` (marker interface), `PushMessage`, `MessagePriority` (`high` = APNs 10 / FCM HIGH; `normal` = APNs 5 / FCM NORMAL, per enum docblock)
- `Message\MessageCompiler` (interface: `compile(Message, Destination, CompileContext): CompiledMessage`, `supports(Message)`)
- `Message\CompileContext`, `CompiledMessage` (headers without authorization; the driver adds URL and auth)
- `Message\Support\TextFitter::fit()`, `Truncator::truncateToBytes()`, `JsonEncoder::encode()` and `JsonEncoder::map()` (the latter forces `{}` for empty maps)
- Exceptions: `ValidationException`, `InvalidMessageException`, `PayloadTooLargeException`, `UnsupportedCapabilityException`

## Worked cases

**A. Long title, truncation requested.**
- Situation: a title is over the APNs limit; the message has `->truncate()`.
- The package: only the title is shortened, on a grapheme boundary, with the ellipsis counted. The `ID` and `data` are untouched.
- The caller sees: a successful compile whose `warnings` say that truncation was applied.

**B. Same long title, no truncation.**
- Situation: the same message without `->truncate()`.
- The package: rejected before any request.
- The caller sees: `PayloadTooLargeException` (a `ValidationException`).

**C. Both FCM Android variants.**
- Situation: a message sets `android` and `androidV2` together.
- The package: the builder rejects it with `InvalidMessageException`.
- The caller sees: an exception before the network.

**D. Unsupported capability with `->lenient()`.**
- Situation: a feature that the target platform does not offer is used, and `->lenient()` is set.
- The package: the capability is dropped and the drop is recorded.
- The caller sees: a result with a warning. Without `lenient`, the caller gets `UnsupportedCapabilityException`.

**E. Policy and action category.**
- Situation: `->policy('marketing')` together with `->actionCategory('ORDER')`.
- The package: two independent fields. The first decides whether the send is allowed; the second tells the app which action set to show.
- The caller sees: consent and caps apply to `marketing`; the device receives only the action category.

## Plan vs code

- The plan's API lists `->policy()` and `->actionCategory()` as distinct; the code's `PushMessage` docblocks say the same.
- Plan §6.3 names `ext-intl` as the preferred grapheme path; the code uses it when the extension is loaded and falls back to PCRE `\X`.
- Over-limit and per-target validation failures: a message that is invalid for every destination is an exception before the network; an error that concerns one destination only (a limit that applies to that target type) becomes a `Rejected` result for that destination and the batch continues (orchestrator's resolution in `README.md`, implemented by the `Sender`).
- `PushMessage::apns()` and `fcm()` call the overlay classes named above. The overlay contract (empty, serializable, returned by `overlay()`) comes from `AGENTS.md`.

## Open points

- **Real-provider verification.** The exact measurement rule of the FCM 4096 and 2048 limits (see above) and the unverified capability cells.
- Whether `->lenient()` warnings should also appear in `SendReport`: today they are in `CompiledMessage::$warnings` only.

---

*This page is generated from [`docs/contracts/messages.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/contracts/messages.md). To change it, edit that file in the repository, not this site.*
