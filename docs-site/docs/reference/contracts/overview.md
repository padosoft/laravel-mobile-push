---
title: "Contracts"
description: "These documents are the binding behaviour that the code implements. They come from docs/PLAN.md and from the interfaces and DTOs in src/Core."
---

# Contracts

These documents are the binding behaviour that the code implements. They come from `docs/PLAN.md` and from the interfaces and DTOs in `src/Core`. Where the plan and the code disagree, each document says so; where the plan is silent, each document lists an open point. Nothing here is a provider fact that is not in those two sources.

## Index

| Document | Covers | Plan |
|---|---|---|
| [outcomes.md](/reference/contracts/outcomes) | Public statuses, `Reason`, when `NotSent` may be claimed, unknown-outcome policy, `SendReport` | §4.1 |
| [identity.md](/reference/contracts/identity) | Scope, app binding, `BindingRef`, typed destinations, pool isolation | §4.2, §6.1 |
| [ledger.md](/reference/contracts/ledger) | Light and durable profiles, effects, deliveries, uniqueness, outbox | §4.3 |
| [classification.md](/reference/contracts/classification) | Single classifier, `Decision`, quarantine and invalidation, generations, environment probe | §4.4 |
| [retry.md](/reference/contracts/retry) | Single budget and deadline, planner, deferral, limits, breakers, provider signals | §4.5 |
| [modes.md](/reference/contracts/modes) | Execution modes, live sends outside production, `Simulated`, `Validated`, official hosts | §4.6 |
| [messages.md](/reference/contracts/messages) | Unified message, overlays, limits, truncation, expiry, capability matrix, FCM target | §6.3, §6.4 |

## Acceptance questions (plan §10, Contract gate)

The gate requires that for "response lost", "token renewed" and "tenant reassigned" there is one public behaviour, stated in writing.

**Response lost.** The request was written and the connection failed before a response. The package cannot prove the provider did not process it, so the destination ends as `OutcomeUnknown`, with no fallback and no invalidation. A replaceable message may be retried by policy; an alert or a `start` is retried only with opt-in. See [outcomes.md](/reference/contracts/outcomes) and [classification.md](/reference/contracts/classification).

**Token renewed.** For a device token, a new registration generation supersedes the old one: a late answer for the old generation never changes the new one, even with an identical token value, and an invalidation applies only when the topic and generation match and the APNs timestamp is later than the registration. For the provider credential, an `ExpiredProviderToken` triggers one coordinated refresh of the affected generation and then one attempt, while concurrent callers reuse the valid token. See [classification.md](/reference/contracts/classification) and [retry.md](/reference/contracts/retry).

**Tenant reassigned.** Every key, cache, limit and ledger row is scoped, and a queued job carries its binding; if the binding no longer resolves to the same identity, the result is `Skipped` with `BindingMismatch`. The plan requires an eligibility check immediately before the network, so a phone moved to another user receives nothing queued for the previous one. The plan does not state which status that eligibility denial produces, so this is an open point in [identity.md](/reference/contracts/identity) and [ledger.md](/reference/contracts/ledger).

## Resolutions (orchestrator, 2026-11-20)

The contract documents list points where the plan and the code disagreed or the plan was silent. These are the decisions; the code is authoritative where the two differ.

| Point | Decision |
|---|---|
| `SendStatus::Failed` is not in plan §4.1 | Added on purpose. `Rejected` = the provider definitively refused the request. `Failed` = known NOT accepted and no attempt left (provider unavailable or unreachable until the retry budget ended). Both allow a fallback; `OutcomeUnknown` never does. |
| `ApnsVoipToken` is not in the plan's destination list | Added on purpose: a PushKit token is a different registration from a device token and must not be interchangeable with it. |
| "An explicit HTTP response" as proof of `NotSent` | Plan wording was loose. An HTTP response is proof that the provider processed the request and gives a *known* outcome; it is never `NotSent`. `NotSent` requires that DNS, connect or TLS failed before any request byte was written. |
| `SendStatus::isTerminal()` is true for `OutcomeUnknown` | Correct. A `SendResult` with `OutcomeUnknown` is final. When the unknown-outcome policy allows another attempt, the result of that pass is `Deferred`, not `OutcomeUnknown`. |
| `SendReport` is single-pass | Intended: it is a cursor. `counts()`, `count()` and `retained()` drain the source; afterwards iteration yields only the retained results. Consume `results()` or `each()` once for the full stream. |
| FCM `app_id` | The binding's `appId` is the Firebase project id. The Android package restriction is a message option (`restricted_package_name`), not part of the identity. |
| Token store unavailable | The store behaves as empty; the token provider keeps one token per process and reuses it within the provider's limits. It never signs per message. |
| Compile errors per destination | A message that is invalid for every destination is an exception before the network. A validation error that concerns one destination only (wrong kind, a limit that applies to that target type) becomes a `Rejected` result for that destination with the validation reason; the rest of the batch continues. Implemented by the `Sender`. |
| `FcmCondition` validation | It is a syntax sanity check on an explicitly typed value, not type inference. Google validates the expression. |
| Truncation without `ext-intl` | Both paths exist and are tested to agree (the PCRE path re-splits consecutive emoji). |
| Quarantined send | Status `Rejected`, reason `DestinationRejected` or `DestinationMismatch`, destination action `Quarantine`. |
| Eligibility denial, blocked live send | Status `Skipped` with `PolicyIneligible` or `PolicySafeMode`. Simulation is `Simulated`. |
| Generation change alone | A different generation of the same identity is NOT a `BindingMismatch`: the current credential for that identity is used. A mismatch is a different identity (scope, provider, app id or environment) behind the same name, or an identity that no longer resolves. |

---

*This page is generated from [`docs/contracts/README.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/contracts/README.md). To change it, edit that file in the repository, not this site.*
