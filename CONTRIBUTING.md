# Contributing

Thank you for helping with `padosoft/laravel-mobile-push`. The package sends push notifications directly to Apple APNs and to Google FCM HTTP v1 from Laravel. Read this page, then [docs/contracts](docs/contracts/README.md) before changing behaviour.

## Setup

Requirements: PHP 8.2 or later (8.3 or later to run the test suite with Laravel 13), Composer, `ext-curl`, `ext-openssl`, `ext-json`. Laravel 12 to 13 is supported.

```bash
composer install
```

Troubleshooting:

- If Composer refuses to run plugins because you are root, run `export COMPOSER_ALLOW_SUPERUSER=1` first. Pest needs its Composer plugin.
- If `composer install` fails because archive downloads from GitHub are blocked, install from source with `composer install --prefer-source`. This is a fallback for restricted networks, not the normal path.

## Verification

Run these before opening a pull request. `composer check` runs the formatting check, PHPStan and the test suite.

```bash
vendor/bin/pest path/to/your/tests            # your tests
vendor/bin/pest --testsuite=Arch              # architecture rules
vendor/bin/phpstan analyse src/Your/Dir --memory-limit=1G   # level max
vendor/bin/pint src/Your/Dir tests/Your/Dir   # formatting (auto-fix)
composer check                                # everything
```

Paste the real output of what you ran into the pull request if something fails.

## What CI runs

- **`tests`** (every push and pull request): the suite on PHP 8.2 (Laravel 12 only), 8.3, 8.4 and 8.5 with Laravel 12 and 13, lowest and latest dependencies; the `slow` group on one combination; Pint and PHPStan; the database-backed scenarios on MySQL 8.4, PostgreSQL 17 and MariaDB 11.4; `fresh-app`, which requires the package into a newly created Laravel application and drives it through install, migrations, commands, a simulated send and the durable queue; and `runtimes`, which does the same on a real Redis (queue and cache), under Horizon and under Octane on RoadRunner.
- **`docs-site`**: the generated documentation site is in sync with `docs/` and builds.
- **`providers`** (weekly and on demand, never on a pull request): Google's live FCM Discovery document against the pinned snapshot, and probes that send made-up credentials to the APNs sandbox, FCM and Google's token endpoint to check that the real refusals are classified as documented. No secret is involved.
- **`mutation`** (weekly and on demand): mutation testing of the dense core classes, and of the database ledger on SQLite, MySQL 8.4 and PostgreSQL 17 (some mutants can only be killed by a server engine). Its score is a signal, not a gate.
- **`backends`** (weekly and on demand): SQS on an emulator (ElasticMQ), memcached and DynamoDB Local as queue and cache of a fresh application; the multi-process cache coordination scenario on memcached and DynamoDB; Octane on Swoole, OpenSwoole and FrankenPHP, including sequential and concurrent requests through the real transport to the local HTTP/2 mock server; Swoole with its coroutine hooks on (the documented unsupported case); a beanstalkd queue; the APNs signer against an emulator of the AWS KMS API.
- **`live-probes`** (by hand only, on `main`, through the protected environment `live-probes` that holds the secrets): `mobile-push:doctor --live` with real credentials of a dedicated test app and Firebase project: one APNs push to an invalid token and one FCM `validate_only` call. Written, never run: no credential was available.
- **`simulator`** (by hand only: macOS runners cost more): `mobile-push:simulator` handing its payload file to `xcrun simctl push` on a booted iOS Simulator.
- **`scaling`** (weekly and on demand): the campaign and durable-profile scaling guards on MySQL 8.4 and PostgreSQL 17, with their timings in the log. MySQL runs with a 2 GB InnoDB buffer pool; with the image default the guards measure the runner's disk and fail.

To run the database-backed scenarios on a server engine locally, set the usual `DB_*` variables (`DB_CONNECTION=pgsql`, ...): `tests/TestCase.php` then empties the schema before each test.

## Tests

- Start with scenario tests in `tests/Scenario/**`. A scenario tells one real situation across the layers, for example "APNs accepts the push but the connection drops before the response": message, compilation, send on a transport, classification, report.
- Assert on observable results: the bytes and headers the transport received, the number of requests counted by the mock server (not by the sender), and the public `SendResult`.
- Add unit tests in `tests/Unit/**` only where the logic is dense and fragile: signatures and DER conversion, truncation, byte limits, classifiers, retry planning, parsers.
- Do not write tests that repeat the implementation, that only assert on mocks of the class under test, or that exist only to raise coverage.
- Use Pest with datasets for tables of cases, and a `Psr\Clock` fake for time.
- No real network. Tests use the local HTTP/2 mock server.
- Every test file must run alone: `vendor/bin/pest tests/Scenario/Foo/BarTest.php`.
- Tests that need Laravel go under `tests/Unit/Laravel` or `tests/Scenario/Laravel`.

## Core does not import the framework

Everything under `src/Core` must not import `Illuminate\*`, `Laravel\*` or `Carbon\*`. Use `Psr\Clock\ClockInterface` for time and `Psr\Log\LoggerInterface` for logging. An architecture test enforces this.

Other rules for code:

- `declare(strict_types=1);` in every PHP file.
- `final` classes by default, `readonly` value objects, backed enums.
- Never throw for a single destination. Exceptions are for globally invalid input or configuration detected before the network; a single destination's failure is a `SendResult`.
- Never log or dump device tokens, bearer tokens, receipt markers or private keys.
- No secrets, real tokens or real keys in code, tests, fixtures, logs or docs. Generate test keys at runtime.

## Proposing a contract change

The documents in `docs/contracts/` and the interfaces and DTOs in `src/Core` are the binding contracts. Do not change them silently.

1. Open an issue with the feature request template. Say which contract document and which section of `docs/PLAN.md` the change affects, and what observable behaviour changes.
2. Wait for a maintainer to agree before writing code that depends on the change.
3. Update the matching document in the same pull request, and add a `CHANGELOG.md` entry under `Unreleased`.

If a contract looks wrong while you implement it, stop and report it instead of working around it.

## Language

Code, comments, public documentation, commit messages and pull requests are in English. The project planning files (`docs/PLAN.md`, `progress.md`, `lesson.md`) are in Italian.

## Security

Do not report vulnerabilities in a public issue. See [SECURITY.md](SECURITY.md).
