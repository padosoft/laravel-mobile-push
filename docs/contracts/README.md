# Contracts

These documents are the binding behaviour that the code implements. They come from `docs/PLAN.md` and from the interfaces and DTOs in `src/Core`. Where the plan and the code disagree, each document says so; where the plan is silent, each document lists an open point. Nothing here is a provider fact that is not in those two sources.

## Index

| Document | Covers | Plan |
|---|---|---|
| [outcomes.md](outcomes.md) | Public statuses, `Reason`, when `NotSent` may be claimed, unknown-outcome policy, `SendReport` | §4.1 |
| [identity.md](identity.md) | Scope, app binding, `BindingRef`, typed destinations, pool isolation | §4.2, §6.1 |
| [ledger.md](ledger.md) | Light and durable profiles, effects, deliveries, uniqueness, outbox | §4.3 |
| [classification.md](classification.md) | Single classifier, `Decision`, quarantine and invalidation, generations, environment probe | §4.4 |
| [retry.md](retry.md) | Single budget and deadline, planner, deferral, limits, breakers, provider signals | §4.5 |
| [modes.md](modes.md) | Execution modes, live sends outside production, `Simulated`, `Validated`, official hosts | §4.6 |
| [messages.md](messages.md) | Unified message, overlays, limits, truncation, expiry, capability matrix, FCM target | §6.3, §6.4 |

## Acceptance questions (plan §10, Contract gate)

The gate requires that for "response lost", "token renewed" and "tenant reassigned" there is one public behaviour, stated in writing.

**Response lost.** The request was written and the connection failed before a response. The package cannot prove the provider did not process it, so the destination ends as `OutcomeUnknown`, with no fallback and no invalidation. A replaceable message may be retried by policy; an alert or a `start` is retried only with opt-in. See [outcomes.md](outcomes.md) and [classification.md](classification.md).

**Token renewed.** For a device token, a new registration generation supersedes the old one: a late answer for the old generation never changes the new one, even with an identical token value, and an invalidation applies only when the topic and generation match and the APNs timestamp is later than the registration. For the provider credential, an `ExpiredProviderToken` triggers one coordinated refresh of the affected generation and then one attempt, while concurrent callers reuse the valid token. See [classification.md](classification.md) and [retry.md](retry.md).

**Tenant reassigned.** Every key, cache, limit and ledger row is scoped, and a queued job carries its binding; if the binding no longer resolves to the same identity, the result is `Skipped` with `BindingMismatch`. The plan requires an eligibility check immediately before the network, so a phone moved to another user receives nothing queued for the previous one. The plan does not state which status that eligibility denial produces, so this is an open point in [identity.md](identity.md) and [ledger.md](ledger.md).
