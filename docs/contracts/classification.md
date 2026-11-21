# Classification

**Purpose.** Defines the single point where a provider answer (or a transport failure) is turned into a `Decision`, what the decision asks for (destination and credential actions), the quarantine and invalidation rules, generations, and the explicit environment probe. Sources: `docs/PLAN.md` §4.4 and §4.5, and `src/Core/Outcome/{OutcomeClassifier,ClassifyContext,Decision,DestinationAction,CredentialAction,ReasonCode}.php`.

## Rules

1. **One classifier per provider.** `OutcomeClassifier::classify(Exchange, ClassifyContext): Decision` is the only code that interprets provider responses or transport failures. The interface docblock states it is pure, total and side-effect free. The implementations are `Apns\Outcome\ApnsOutcomeClassifier` (with `ApnsReasonCatalog`) and `Fcm\Outcome\FcmOutcomeClassifier` (with the FCM reason catalog); `Laravel\Testing\FakeClassifier` is the test double.
2. Every possible exchange maps to exactly one `Decision`. Unknown provider strings map to `ReasonCode::Unknown` with the raw string preserved; the classifier never throws.
3. Listeners and breakers react only to the final `Decision`, never to intermediate responses (plan §4.4).
4. `ClassifyContext` carries the destination, the binding and `validateOnly`. A 2xx answer to an FCM `validate_only` request is `Validated`, not `Accepted`.
5. **Quarantine is not invalidation.** Quarantine suspends the registration in its scope and discards the current send. Invalidation removes the registration as stated by the provider.
6. Invalidation applies only to the generation that was attempted (see Generations).
7. The environment is never changed during a send (see Environment probe).

## Decision fields

| Field | Meaning |
|---|---|
| `status` | Status if no further attempt is made: `Accepted`, `Validated`, `Rejected`, `Failed` or `OutcomeUnknown`. `Deferred` and `Expired` are assigned later by the retry planner |
| `reason` | `Reason` (code, raw reason, hint) |
| `retryable` | Whether another attempt is allowed at all; still subject to the budget and the unknown-outcome policy |
| `minDelaySeconds` | Minimum wait from provider guidance or `Retry-After` |
| `destinationAction` | `none`, `quarantine` or `invalidate` |
| `credentialAction` | `none`, `refresh_token`, `slow_token_refresh` or `report_auth_failure` |
| `throttleProvider` | Provider-wide slowdown signal for the shared throttle gate |
| `invalidatedAtMs` | APNs 410 `timestamp` in Unix milliseconds |
| `httpStatus`, `providerMessageId`, `meta` | Provider facts, e.g. `apns-unique-id` in `meta` |

## Destination and credential actions

| Action | Effect (plan) |
|---|---|
| `DestinationAction::Quarantine` | Current send discarded; registration suspended in its scope; no retry |
| `DestinationAction::Invalidate` | Registration invalidated, only for the attempted generation |
| `CredentialAction::RefreshToken` | One coordinated refresh of the provider token for the generation that failed, then one more attempt |
| `CredentialAction::SlowTokenRefresh` | Slow token renewals; never fail over to the secondary credential for this |
| `CredentialAction::ReportAuthFailure` | The credential itself is wrong; feeds the authentication breaker, which decides failover once |

## Quarantine and invalidation

Public result of a quarantined send: `SendStatus::Rejected` with `ReasonCode::DestinationRejected` or `DestinationMismatch` and `DestinationAction::Quarantine` (orchestrator's resolution, `README.md`). `Laravel\Devices\DeviceOutcomeApplier` applies the action to the registry: quarantine suspends the device row; invalidation only touches the generation that was attempted and only when the APNs `timestamp` is later than the registration.

| Provider answer | Destination action (plan §4.4) |
|---|---|
| APNs `BadDeviceToken` (token or environment wrong) | Quarantine |
| APNs `DeviceTokenNotForTopic` | Quarantine |
| FCM `SENDER_ID_MISMATCH` | Quarantine |
| APNs 410 `Unregistered` or `ExpiredToken` | Invalidate, **only** if topic and generation match and the `timestamp` (Unix ms) is later than the registration |
| FCM `UNREGISTERED` | Invalidate the destination that was attempted |
| FCM `INVALID_ARGUMENT` | None. Read `error.details`; this normally points to the payload |

Code: `ReasonCode::DestinationRejected` (annotated as APNs `BadDeviceToken`), `DestinationMismatch` (annotated as `DeviceTokenNotForTopic` and `SENDER_ID_MISMATCH`), `DestinationUnregistered` (annotated as APNs 410 and FCM `UNREGISTERED`).

## Generations

- A `BindingRef` generation changes on credential rotation or reconfiguration. A `DestinationRef` generation is the version of the registry row.
- A late answer for generation 7 never touches generation 8, even when the raw token is identical (plan §4.4).
- An APNs 410 older than the registration timestamp does not invalidate the current registration.

## Environment probe

- The environment of a device is the one the app declared at registration. The send path never switches it.
- The operator runs `mobile-push:devices:probe-environment {--device=} {--quarantined} {--allow-cross-environment}` (plan §4.4; `Laravel\Console\Ops\ProbeEnvironmentCommand` and `EnvironmentProber`). It sends one silent background push per quarantined device through the connection of the same scope and app on the other environment, one device at a time, and re-labels the registration only when Apple accepts.
- **Rate limit.** A run is capped by `--limit` (default 10, at most 100) and a device is contacted **at most once per `devices.probe.cooldown_hours`** (default 24); the time and outcome of the last probe are kept in the device row (`metadata.environment_probe`). `--force` does not bypass it; `--ignore-cooldown` does after an interactive confirmation.
- **Keys bound to one environment.** An authentication refusal (`ReasonCode::AuthTokenInvalid` with `ReportAuthFailure`, which is what `BadEnvironmentKeyIdInToken` and `BadEnvironmentKeyInToken` map to; also the other `STOPS` codes of `EnvironmentProber`) stops the **whole run**: no further device is contacted.
- The probe asks the safe-mode guard first (`OperationKind::Probe`): in simulation nothing is sent.

## Types

- `Outcome\OutcomeClassifier` (interface), `ClassifyContext`, `Decision`, `DestinationAction`, `CredentialAction`, `ReasonCode`, `Reason`
- Inputs: `Transport\Exchange`, `Identity\Destination`, `Identity\BindingRef`

## Worked cases

**A. Wrong environment token.**
- Situation: an APNs production send returns `BadDeviceToken`.
- The package: `Decision` with `DestinationAction::Quarantine` and status `Rejected`.
- The caller sees: `Rejected` / `DestinationRejected`, no retry; the registration is suspended in its scope; the environment is not changed. The operator decides with the probe.

**B. Old unregistration.**
- Situation: APNs returns 410 with a `timestamp` earlier than the device's last registration.
- The package: no invalidation; the registration stays.
- The caller sees: the failed result for that send. The current registration is untouched.

**C. Late answer after re-registration.**
- Situation: generation 8 is registered; a response for generation 7 arrives and says 410.
- The package: the invalidation applies only to the attempted generation.
- The caller sees: generation 8 is unchanged, even though the token value is identical.

**D. Validate-only success.**
- Situation: an FCM request with `validateOnly` gets a 2xx.
- The package: `Validated`, not `Accepted`.
- The caller sees: a result that says the request was validated, not sent.

## Plan vs code

- Plan §4.4 says the quarantine response is the "current send discarded" without a `SendStatus`; the code makes it `Rejected` (see above).
- The two environment-key strings of plan §13 are in the APNs reason catalog (`BadEnvironmentKeyIdInToken` and the reported variant `BadEnvironmentKeyInToken`, both `AuthTokenInvalid` with `ReportAuthFailure`); `ExpiredProviderToken` is `AuthTokenExpired` with `RefreshToken` (retryable, once); `TooManyProviderTokenUpdates` is `AuthTokenThrottled` with `SlowTokenRefresh`.
- The `ReasonCode` annotations and the catalog texts are the author's reading of the provider documentation, not checked against live provider answers.

## Open points

- **Real-provider verification.** The strings of the APNs and FCM catalogs (including the two environment-key variants and `TooManyProviderTokenUpdates` scope) were never compared with real Apple and Google answers.
- Behaviour of `ReasonCode::Unknown` for a 5xx that is neither listed nor known: it is kept with its raw string, but whether it should be retried more or less aggressively than a listed 5xx is not decided.
- The cooldown (24 h) and the cap (10 per run) of the environment probe are defaults chosen by the package, not values from Apple.
