# Agent instructions — padosoft/laravel-mobile-push

These rules apply to every agent and sub-agent working in this repository (Claude Code, Codex, or any other). `CLAUDE.md` imports this file.

## What this package is

A Laravel package that sends push notifications **directly** to Apple APNs (HTTP/2 provider API, Live Activities and broadcast channels included) and to Android through Google FCM HTTP v1. Namespace `Padosoft\MobilePush`, facade `MobilePush`, config `mobile-push.php`, commands `mobile-push:*`, env `MOBILE_PUSH_*`, tables `mobile_push_*`. PHP 8.3+, Laravel 12–13.

The full approved plan is `docs/PLAN.md` (Italian). The binding rules are the contracts in `docs/contracts/` and the interfaces/DTOs already in `src/Core`.

## Before you start any task — mandatory

1. Read `progress.md` (where the project is, what is in flight, what is next).
2. Read `lesson.md` (discoveries and mistakes already made — do not repeat them).
3. Read the contracts that touch your task: `docs/contracts/*.md` and the relevant `src/Core/**` interfaces.
4. Read the section of `docs/PLAN.md` that covers your task.

## When you finish any task — mandatory

1. Run the verification commands below for what you touched and make them pass.
2. Append one entry to the log at the bottom of `progress.md` with a shell append, never by rewriting the file (other agents append concurrently):
   `printf '%s\n' "- $(date -u +%F\ %H:%M) [<task id>] <done | blocked>: <one line: what now works, what is left>" >> progress.md`
3. Append to `lesson.md` (same append technique, use the template at the top of that file) every non-obvious thing you learned: a curl/PHP/Laravel behaviour, a protocol fact you verified or disproved, a mistake you made and the rule that avoids it. If you learned nothing new, append nothing.
4. In your final message list: files created/changed, commands run with their real result, anything you could not verify, and any change you need outside your own directories.

The status table at the top of `progress.md` is maintained by the orchestrator only.

## Hard rules

- **Stay in your lane.** Only create or edit files inside the directories your brief assigns to you. Never edit `composer.json`, `phpunit.xml`, `phpstan.neon.dist`, `pint.json`, `tests/Pest.php`, `tests/TestCase.php`, `AGENTS.md`, `CLAUDE.md`, `docs/PLAN.md`, or the shared contracts listed below. If you need a change there, do not make it: describe it in your final message.
- **Shared contracts are frozen** (owned by the orchestrator): everything directly in `src/Core/Transport/` except the `Curl/` sub-directory, `src/Laravel/Contracts/**`, `src/Laravel/Module.php`, `src/Core/Outcome/{SendStatus,ReasonCode,Reason,Decision,DestinationAction,CredentialAction,SendResult,SendReport}.php`, `src/Core/Identity/**`, `src/Core/Message/{Message,MessagePriority,CompileContext,CompiledMessage,MessageCompiler,PushMessage}.php`, `src/Core/Auth/{Signer,SecretResolver,AuthToken,TokenStore,AccessTokenProvider}.php`, `src/Core/Exceptions/**`.
- **`src/Core` never imports the framework**: no `Illuminate\*`, `Laravel\*`, `Carbon\*`. Use `Psr\Clock\ClockInterface` for time and `Psr\Log\LoggerInterface` for logging. An architecture test enforces this.
- `declare(strict_types=1);` in every PHP file. `final` classes by default, `readonly` value objects, backed enums.
- **Never install dependencies** and never run `composer require/update`. If you need a package, say so.
- **No secrets, no real tokens** in code, tests, fixtures, logs or docs. Test keys are generated at runtime with `openssl_pkey_new()`.
- **Never throw for a single destination.** Exceptions are only for globally invalid input/configuration detected before the network. Per-destination failures are `SendResult`s.
- **An unknown outcome is not a failure.** Report `TransportEvidence::NotSent` only with proof (DNS/connect/TLS failed before any request byte was written, or an explicit HTTP response exists). Everything else is `Unknown` → `SendStatus::OutcomeUnknown`.
- **Never infer** provider, token type, bundle or environment from the shape of a string. Destinations are typed objects.
- **Never log or dump** device tokens, bearer tokens, receipt markers or private keys. Value objects redact themselves in `__debugInfo`.
- Do not use `sleep()` longer than a few milliseconds in library code; long waits become `Deferred` outcomes.
- Do not weaken a test to make it pass. If a contract looks wrong, stop and report it.

## Laravel layer: modules

`src/Laravel/MobilePushServiceProvider.php` is owned by the orchestrator. Each slice of the Laravel layer is a **module**: a class implementing `Padosoft\MobilePush\Laravel\Module` (`static register(Application)`, `static boot(Application)`) at the path listed in `MobilePushServiceProvider::MODULES`. Put your container bindings, migrations, commands and listeners in your module; never edit the provider. Read config with explicit defaults (`config('mobile-push.devices.table', 'mobile_push_devices')`) and list in your final message every config key you introduced (name, env var, default, allowed values): the orchestrator adds them to `config/mobile-push.php`. Use `Psr\Clock\ClockInterface` from the container for time. Migrations live in `database/migrations/`, are idempotent, and are **published** (`mobile-push:install` or the module's publish tag); a module registers its files with `migrate` itself only when `mobile-push.migrations.autoload` is true and no published copy exists. Wire them through `Integration\ModuleMigrations::wire()`, never by hand.

## Laravel layer: contracts

`src/Laravel/Contracts/**` is frozen (orchestrator): `PushDispatcher` (resolve a binding, send through the full pipeline, get a driver, default send options), `QueuedSender` and `QueueOptions`. Modules depend on these contracts, never on the manager class; in tests, bind a small fake `PushDispatcher` or bind `FakeTransport` as `Transport`.

The send pipeline lives in `src/Core/Driver` (`Sender`, `Driver`, `SendOptions`, `SendItem`, `SendObserver`, `DestinationGate`, `GateDenial`). The report returned by a send is a cursor and must be consumed.

## Documentation

Public docs are `README.md` and `docs/*.md` (English). `docs/configuration.md` is GENERATED from the config schema: never edit it by hand. Every statement must be true of the code as it is; do not document a feature that is not implemented, and say plainly what is not verified against real providers.

PHP examples that can run are marked and executed by `tests/Scenario/Laravel/Docs/DocExamplesTest.php`: put an HTML comment `<!-- example: short-id -->` on the line before a fenced ```php block. Each marked block runs alone inside an app where `MobilePush::fake()` is active, with `$fake`, `$iosToken`, `$androidToken` and `$user` (a notifiable with one iOS and one Android destination) in scope, and may start with its own `use` lines. Mark every example that can run this way; unmarked blocks are illustrative (config arrays, Swift/Kotlin, shell). Run `vendor/bin/pest tests/Scenario/Laravel/Docs` before finishing.

## Testing style (what the project owner asked for)

- **Prefer high-level scenario tests** that cross layers: message → compilation → send on a transport → classification → report. They live in `tests/Scenario/**` and read like a story of one real situation ("APNs accepts the push but the connection drops before the response").
- **Targeted unit tests only where logic is dense and fragile**: signatures and DER conversion, truncation, byte limits, classifiers, retry planning, parsers. They live in `tests/Unit/**`.
- Do not write tests that restate the implementation, that only assert on mocks of the class under test, or that exist to raise coverage.
- Every scenario asserts on an **observable oracle**: the bytes/headers a transport received, the number of requests counted by the mock server (not by the sender), the public `SendResult`.
- Tests that need Laravel go under `tests/Unit/Laravel` or `tests/Scenario/Laravel` (they get the Testbench `TestCase` automatically). Everything else must run without the framework.
- Use Pest (`it('...')`, `expect()`), datasets for tables of cases, and `Psr\Clock` fakes for time. No real network except the local HTTP/2 mock server.
- Each test file must be runnable alone: `vendor/bin/pest tests/Scenario/Foo/BarTest.php`.

## Verification commands

```bash
vendor/bin/pest path/to/your/tests            # your tests
vendor/bin/pest --testsuite=Arch              # architecture rules
vendor/bin/phpstan analyse src/Your/Dir --memory-limit=1G   # level max
vendor/bin/pint src/Your/Dir tests/Your/Dir   # formatting (auto-fix)
composer check                                # everything (orchestrator runs this between waves)
```

Report real output. If something fails and you cannot fix it inside your lane, say so plainly — do not hide it.

## Key contracts in one page

- **Transport** (`Core\Transport\Transport`): `send(iterable<Request>, ConnectionProfile): Generator<string, Exchange>`; one `Exchange` per request, keyed by `Request::$id`; never throws for one request. `FakeTransport` is the in-memory implementation for tests.
- **Outcome** (`Core\Outcome`): `SendStatus` (`Accepted`, `Rejected` = provider refused, `Failed` = known not accepted and no attempt left, `Deferred`, `OutcomeUnknown`, `Expired`, `Skipped`, `Simulated`, `Validated`); `Reason { ReasonCode code, ?string rawReason, ?string hint }`; `Decision` is the single classification of an exchange; `SendResult` per destination; `SendReport` is a streaming cursor with bounded memory.
- **Identity** (`Core\Identity`): `BindingRef (scope, name, provider, appId, environment, generation)` travels in jobs; typed destinations in `Core\Identity\Destination\*` built with `::of($value)`.
- **Message** (`Core\Message`): unified `PushMessage`; native messages implement `Message`; a `MessageCompiler` turns `(Message, Destination, CompileContext)` into a `CompiledMessage` (headers without authorization, JSON body, counted bytes). `PushMessage::apns()/fcm()` call `Core\Apns\Message\ApnsMessage::overlay()` and `Core\Fcm\Message\FcmMessage::overlay()`, which must return an empty, serializable instance.
- **Auth** (`Core\Auth`): `Signer` (ES256 returns raw 64-byte `R||S`), `AccessTokenProvider`, `TokenStore` with compare-and-swap.

## Language

Code, comments, commit messages, `AGENTS.md` and public docs are in English. `progress.md`, `lesson.md` and `docs/PLAN.md` are in Italian (the project owner's language).
