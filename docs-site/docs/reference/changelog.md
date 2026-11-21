---
title: "Changelog"
description: "All notable changes to this project are documented in this file."
---

# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- Package skeleton: Composer metadata for `padosoft/laravel-mobile-push` (namespace `Padosoft\MobilePush`), the `mobile-push.php` configuration file, the service provider and helpers files, and the test and static-analysis setup.
- Core contracts in `src/Core`:
  - transport interface and value objects (`Transport`, `Request`, `Response`, `Exchange`, `TransportFailure`, `ConnectionProfile`) and an in-memory `FakeTransport`;
  - outcome types (`SendStatus`, `Reason`, `ReasonCode`, `Decision`, `SendResult`, `SendReport`) and the `OutcomeClassifier` interface;
  - identity types (`BindingRef`, `Scope`, `Provider`, `Environment`, `Platform`) and typed destinations for APNs and FCM;
  - message types (`PushMessage`, `Message`, `MessageCompiler`, `CompileContext`, `CompiledMessage`) with JSON, truncation and text-fitting helpers;
  - authentication interfaces (`Signer`, `AccessTokenProvider`, `TokenStore`, `SecretResolver`) and the `AuthToken` value object;
  - the exception hierarchy under `Core\Exceptions`.
- Contract documents in `docs/contracts/`.
- Repository boilerplate: `CONTRIBUTING.md`, `SECURITY.md`, issue and pull request templates, and the CI workflow.

---

*This page is generated from [`CHANGELOG.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/CHANGELOG.md). To change it, edit that file in the repository, not this site.*
