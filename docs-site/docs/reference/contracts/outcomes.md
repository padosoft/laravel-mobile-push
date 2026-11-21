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
- An explicit HTTP response is **not** `NotSent`: it proves the provider processed the request and gives a known outcome (orchestrator's resolution, `README.md`).
- `REFUSED_STREAM` and the streams above the last `GOAWAY` id **stay `Unknown`**: the M1 measurements (`docs/transport.md`) showed the curl binding cannot tell them apart from a connection that died after processing (`CurlErrorClassifier` claims `NotSent` only for the resolve, connect and TLS phases, when nothing of the request was written).
- What follows a proven `NotSent`: the classifier returns a retryable decision with `ReasonCode::TransportNotSent` (`ApnsOutcomeClassifier`, `FcmOutcomeClassifier`); the retry planner then defers it, or ends it as `Failed` when no attempt is left. It is never reported as `OutcomeUnknown`.

## Types

- `Padosoft\MobilePush\Core\Outcome\SendStatus` (enum), `Reason`, `ReasonCode` (enum), `SendResult`, `SendReport`
- `Padosoft\MobilePush\Core\Outcome\Decision`, `DestinationAction`, `CredentialAction` (see `classification.md`)
- `Padosoft\MobilePush\Core\Transport\Exchange`, `TransportFailure`, `TransportEvidence`, `Response`
- `Padosoft\MobilePush\Core\Exceptions\PushException`, `ValidationException`, `InvalidMessageException`, `SendFailedException`

## Worked cases

**A. Response lost.**
- Situation: the request was written, then the connection reset before any response arrived.
- The package: the transport yields a failure with `Unknown` evidence. No proof of non-processing exists, so nothing else follows (no fallback, no invalidation).
- The caller sees: `OutcomeUnknown` for that destination, counted by `hasUnknown()`. A replaceable message may be retried by the planned policy; an alert is not retried unless opted in. The `ReasonCode` is `TransportUnknown` (the two classifiers above produce it for any failure without proof of non-processing, a timeout after the write included).

**B. DNS failure before connect.**
- Situation: the host does not resolve.
- The package: proven `NotSent`, because no request byte was written.
- The caller sees: a result that is not `OutcomeUnknown`: `Deferred` with `TransportNotSent` while attempts remain (retrying is safe), `Failed` with `RetryBudgetExhausted` once they are spent.

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

- `Failed` is in the code (`SendStatus::Failed`) and in `AGENTS.md`, but plan §4.1 does not list it. This document follows the code (`Rejected` = refused by the provider, `Failed` = known not accepted and no attempt left).
- `TransportEvidence::NotSent` docblock lists only DNS, connect and TLS; the plan's "an explicit HTTP response" proof was dropped on purpose (see above).
- `SendStatus::isTerminal()` returns true for everything except `Deferred`, so it is true for `OutcomeUnknown`: a result with that status is final. When the unknown-outcome policy allows another attempt, the result of that pass is `Deferred`. Do not use `isTerminal()` to decide retries; use the planner.
- The plan does not say how many results a `SendReport` retains. The code default is 1000.

## Open points

- **Real-provider verification.** Which transport failures after the write really leave the provider's state unchanged was measured only against the local HTTP/2 mock server (`docs/transport.md`), never against Apple or Google; that is why they all stay `Unknown`.
- The `ReasonCode` set for a timeout after the write is the single `TransportUnknown`; whether a finer split (timeout, reset, protocol error) would help operators is a product question, not a contract one.

---

*This page is generated from [`docs/contracts/outcomes.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/contracts/outcomes.md). To change it, edit that file in the repository, not this site.*
