---
title: "Upgrading and versioning"
description: "This page states the rules the package follows between versions, so you know what may change under you and what will not. It does not contain a release history: the list of changes of every version lives in CHANGELOG.md."
---

# Upgrading and versioning

This page states the rules the package follows between versions, so you know what may change under you and what will not. It does not contain a release history: the list of changes of every version lives in `CHANGELOG.md`.

Related pages: [commands](/operations/commands), [outcomes](/reliability/outcomes), [delivery profiles](/reliability/delivery-profiles), [configuration](/get-started/configuration).

## Versioning policy

The package follows semantic versioning from 1.0.0: **a breaking change of the public contract below needs a new major version**; a minor version adds, a patch version fixes. Require `^1.0` in `composer.json` and read `CHANGELOG.md` before moving to a new minor.

| May change in a minor version | Does **not** change within a major version |
|---|---|
| Signatures and constructors of classes that are not part of the contracts below, and anything marked `@internal` | The values of `SendStatus` (`accepted`, `rejected`, `failed`, `deferred`, `outcome_unknown`, `expired`, `skipped`, `simulated`, `validated`) and what each one means |
| New config keys, new defaults of **optional** modules, renamed keys (with a note) | The **exit codes** of the documented commands (see [commands](/operations/commands)) |
| New columns and tables (always as new migration files) | The rules in [outcomes](/reliability/outcomes): `Accepted` is not delivery; an unknown outcome starts no fallback and no invalidation |
| New `ReasonCode` values and new entries in the APNs and FCM reason catalogs | The documented guarantees and their stated limits ([delivery profiles](/reliability/delivery-profiles)) |
| New events, new fields on an event's view, new options on a command | The names of the facade, the config file (`mobile-push.php`), the commands (`mobile-push:*`), the env prefix `MOBILE_PUSH_*` and the tables (`mobile_push_*`) |
| Wording of hints and messages | The safe-mode rules: a send outside production is simulated unless explicitly allowed |

Consequences for your code:

- **Do not `match` exhaustively on `ReasonCode`** without a default arm: new codes can appear in any release. Branch on `SendStatus` (stable), and use the reason code for logging, counting and fine-tuning.
- **Test the exit codes**, not the text, in scripts around the commands.
- A removed or renamed public method, option or key is mentioned in the changelog of the release that does it. It is not silently re-purposed.

At 1.0 the contracts above become the stable public API in the strict semver sense: breaking changes only in a major version, with deprecation first.

## Configuration across versions

- **New keys always have a default that preserves the old behaviour.** Optional modules stay **off** by default.
- The package reads every key with an explicit default, and Laravel's `mergeConfigFrom()` is documented to merge only the first level of the config array. If your published `config/mobile-push.php` defines a whole section (for example `queue`), keys that a later version adds to that section are not merged into your array: they take their built-in default. Nothing breaks, but you do not see the new key in your file. Diff your file against the new `config/mobile-push.php` after an upgrade.
- [`docs/configuration.md`](/get-started/configuration) is **generated** from the config schema of the installed version (key, environment variable, default, allowed values), and a test fails when the schema and the config file disagree. Use it as the reference for the version you run.
- After changing the configuration or upgrading: `php artisan config:clear` (and `config:cache` again on production), then `php artisan mobile-push:doctor`.

## Migrations across versions

- A schema change ships as a **new migration file** with a later name, never as an edit of one that has been released.
- Migrations are **published, not auto-loaded** (`migrations.autoload`, `MOBILE_PUSH_MIGRATIONS_AUTOLOAD`, default `false`): after an upgrade, run `php artisan vendor:publish --tag=mobile-push-migrations-<module>` (or `php artisan mobile-push:install`, which publishes a module's group when any of its files is missing). Publishing copies the whole group under new names; the files you already had run once and the copies are skipped by their `hasTable` / `hasColumn` / index guards, so you may delete the older duplicates afterwards. Then `php artisan migrate`. With autoload on, the enabled modules hand their package files to `migrate` and the new files arrive with the upgrade; nothing is published, and a copy you published earlier takes precedence over the package file ([delivery profiles](/reliability/delivery-profiles#delivery-profiles-light-and-durable-switching-migrations)). Upgrading from a build that auto-loaded **and** published: see [troubleshooting](/operations/troubleshooting).
- **Scaling indexes (campaign and relay work).** Two migrations only add indexes: `2026_11_21_000013_add_mobile_push_ledger_scaling_indexes.php` (in the ledger group; it adds the claim, effect-claim and work indexes of the deliveries table and drops the two index prefixes they replace) and `2026_11_21_000002_add_mobile_push_devices_audience_index.php` (in the devices group; the audience index). An application that published its migrations **before** these files existed must publish again (`php artisan mobile-push:install`, or `vendor:publish` with the `-ledger` and `-devices` tags) and run `php artisan migrate`; without them nothing breaks, but claims and audience reads on large tables are slow. They are idempotent (an index that exists is not created again) and run on SQLite and, in CI, on MySQL 8.4 and PostgreSQL 17 (on empty tables: how long they take on a large table was not measured). On a table with many rows creating an index takes time and locks according to your database engine: run it in a quiet period.
- Receipts add columns to the deliveries table through their own migration (`..._create_mobile_push_ledger_receipt_columns`), which is part of the ledger set.
- Stored data carries format versions where it matters (a stored retry budget has a `v` field; an encrypted value names its key version; a payload fingerprint names its scheme). A version that does not recognise a stored format refuses it instead of guessing. **Downgrading a version with stored ledger rows is not supported.**

## Upgrade checklist

1. Read `CHANGELOG.md` for every version between yours and the target.
2. Update the package with Composer (your own command: this package never runs it for you).
3. `php artisan migrate`, and `php artisan config:clear`.
4. Compare your published config with the new file.
5. **Restart queue workers** (`php artisan queue:restart`, or `horizon:terminate`). A long-running worker keeps the old code in memory. Let in-flight jobs finish: stopping workers abruptly leaves `outcome_unknown` destinations (see [troubleshooting](/operations/troubleshooting#troubleshooting-by-symptom-unknown-outcomes-after-a-deploy)).
6. `php artisan mobile-push:doctor`, then send a canary push on a test device.
7. On the **light** profile, jobs queued by the old version are still run by the new one if their format is unchanged; the changelog says when it is not. If you are unsure, drain the queue before the upgrade.

## Not verified

What is tested: `UpgradeMigrationsTest` installs the durable profile with the first generation of migration files only (as an application that published its migrations before the two index migrations existed), does real work on that schema (30 devices registered, 120 deliveries queued, a worker holding ten leases, some of them recorded), then upgrades the way this page says: `mobile-push:install` again publishes only the two missing files, `migrate` runs exactly those two, no row changes, the worker that held its leases across the upgrade finishes its window, the rest is relayed once, and the two migrations can be rolled back and run again. It runs on SQLite and, in CI, on MySQL 8.4, MariaDB 11.4 and PostgreSQL 17. `ModuleCombinationsTest` builds an application for every combination of the five optional modules with both profiles (64) and takes each through install, migrate, doctor, health, a push and rollback; the one combination the package refuses (receipts with the light profile) is refused before anything is published.

What is not: there is no released version yet, so "upgrade" means the only two schema generations that exist. An upgrade between two **releases**, with their queued job payloads, will need its own test when the second release is cut. Until then, rehearse each upgrade of a durable-profile installation on a copy of your database.

---

*This page is generated from [`docs/upgrading.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/upgrading.md). To change it, edit that file in the repository, not this site.*
