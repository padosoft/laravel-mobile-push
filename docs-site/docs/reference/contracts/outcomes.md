---
title: "Outcomes"
description: "Defines what a result means for the caller: the status of each destination, the reason attached to it, when a failure may be called \"not sent\", what happens to an unknown outcome, and how results are read from a report."
---

# Outcomes

**Purpose.** Defines what a result means for the caller: the status of each destination, the reason attached to it, when a failure may be called "not sent", what happens to an unknown outcome, and how results are read from a report. Sources: `docs/PLAN.md` §4.1 and §4.5, and the code in `src/Core/Outcome` and `src/Core/Transport`.

## Rules

1. `Accepted` means the provider accepted the request. It never means delivered, displayed or seen. `SendStatus::isAccepted()` is true only for `Accepted`; `Validated` is not accepted.
2. Every destination ends with one `SendStatus`. A `Deferred` destination carries `nextAttemptAt`.
3. A `Reason` is `{ code: ReasonCode, rawReason: ?string, hint: ?string }`. The enum never carries provider text. `rawReason` holds it sanitized: bytes outside printable ASCII become `?`, and the value is cut to 120 characters.
4. A transport failure is `TransportEvidence::NotSent` only with proof: DNS, connect or TLS failed before any request byte was written. Everything else is `Unknown`, and an unknown transport failure becomes `OutcomeUnknown`.
5. An unknown outcome starts nothing else: no fallback to another channel and no invalidation of the destination.
6. Retry from an unknown outcome follows `unknown_outcome.retry = never | replaceable | always` (default `replaceable`). Replaceable messages are Live Activity updates and ends, background, widget and control pushes, and messages marked `->replaceable()`. Alerts and `start` are not retried without opt-in.
7. Exceptions are reserved for globally invalid input or configuration detected before the network. A failure of one destination is a result, never an exception.
8. `SendReport::throw()` is the only method that turns non-accepted results into an exception, and it runs only when the caller calls it.
9. A `SendReport` streams. It keeps counters in memory and the first `retain` results (constructor default 1000); `results()` and `each()` read the rest from the source.

## Statuses

| Status | Meaning (code and plan) | What it does NOT mean | Further attempts |
|---|---|---|---|
| `Accepted` | The provider accepted the request | Not delivered, not seen | None |
| `Rejected` | The provider refused it definitively | Not a temporary error | None: an unchanged retry cannot succeed |
| `Failed` | Known not accepted, and no attempt left | Not unknown | None |
| `Deferred` | A new attempt is planned; `nextAttemptAt` is set | Not failed | Yes, at `nextAttemptAt` |
| `OutcomeUnknown` | Not known whether the provider processed it | Not a failure, not "not sent" | Only per the unknown-outcome policy |
| `Expired` | The logical expiry passed before an attempt | Nothing was sent | None |
| `Skipped` | A local rule prevented the attempt (consent, cap, safe mode, open circuit, binding mismatch) | Not a provider decision | None |
| `Simulated` | No network was touched | Not sent | None |
| `Validated` | FCM `validate_only` was validated by the provider | Not sent | None |

## When NotSent may be claimed

- Proven: DNS, connect or TLS failed before the request was written. The transport reports `TransportFailure` with `evidence = NotSent` and `requestWritten = false`.
- Not proven: a generic HTTP/2 error, a timeout or a reset after the write, an unparseable response. These are `Unknown`.
- Plan §4.1 lists "an explicit HTTP response" as a second proof. The code does not model this (see Plan vs code).
- Plan §4.1 says `REFUSED_STREAM` and the last stream of `GOAWAY` become proof only if M1 measurements show the binding distinguishes them reliably. Not decided yet.

## Types

- `Padosoft\MobilePush\Core\Outcome\SendStatus` (enum), `Reason`, `ReasonCode` (enum), `SendResult`, `SendReport`
- `Padosoft\MobilePush\Core\Outcome\Decision`, `DestinationAction`, `CredentialAction` (see `classification.md`)
- `Padosoft\MobilePush\Core\Transport\Exchange`, `TransportFailure`, `TransportEvidence`, `Response`
- `Padosoft\MobilePush\Core\Exceptions\PushException`, `ValidationException`, `InvalidMessageException`, `SendFailedException`

## Worked cases

**A. Response lost.**
- Situation: the request was written, then the connection reset before any response arrived.
- The package: the transport yields a failure with `Unknown` evidence. No proof of non-processing exists, so nothing else follows (no fallback, no invalidation).
- The caller sees: `OutcomeUnknown` for that destination, counted by `hasUnknown()`. A replaceable message may be retried by the planned policy; an alert is not retried unless opted in. The `ReasonCode` is a transport code (`TransportUnknown`); the exact mapping belongs to the classifier, which is not written yet.

**B. DNS failure before connect.**
- Situation: the host does not resolve.
- The package: proven `NotSent`, because no request byte was written.
- The caller sees: a result that is not `OutcomeUnknown`. The public status for this proof is not stated in the plan (see Open points).

**C. Batch of three, two definitive and one unknown.**
- Situation: two destinations are refused, one has an unknown outcome.
- The package: one `SendResult` per destination; no exception is raised.
- The caller sees: `counts()` gives the tallies and `each()` visits the results. Only an explicit `->throw()` turns this batch into a `SendFailedException`.

**D. Invalid message.**
- Situation: a payload value is not valid UTF-8.
- The package: `JsonEncoder` raises `InvalidMessageException` before any request is built. The message is invalid for every destination, so no per-destination result exists.
- The caller sees: an exception and no report.

## Caveat: the report is a single pass

`counts()`, `count()`, `accepted()` and `retained()` drain the source. After that, iterating `results()` yields only the retained results. Verified with a scratch script on this code: five results, `retain = 2`; `counts()` first, then iteration returned 2 items; a second iteration returned 2 items. Read the results with `each()` or `results()` before asking for counts, or use `counts()` alone when only tallies are needed.

## Plan vs code

- `Failed` is in the code (`SendStatus::Failed`) and in `AGENTS.md`, but plan §4.1 does not list it. This document follows the code.
- `TransportEvidence::NotSent` docblock lists only DNS, connect and TLS. Plan §4.1 also allows "an explicit HTTP response". `Response`'s docblock says a received response is proof that the provider processed the request. These three statements do not fit together yet.
- `SendStatus::isTerminal()` returns true for everything except `Deferred`, so it returns true for `OutcomeUnknown`. Plan §4.1 allows replaceable unknown outcomes to be retried. Do not use `isTerminal()` to decide retries.
- The plan does not say how many results a `SendReport` retains. The code default is 1000.

## Open points

- The public status produced by a proven `NotSent` (plan silent).
- Whether an explicit HTTP response can serve as `NotSent` proof (plan §4.1 and code disagree).
- Whether `REFUSED_STREAM` and the last `GOAWAY` stream become proof (plan §4.1, pending M1).
- The `ReasonCode` set for a timeout after the write (only `TransportUnknown` exists in the enum).

---

*This page is generated from [`docs/contracts/outcomes.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/contracts/outcomes.md). To change it, edit that file in the repository, not this site.*
