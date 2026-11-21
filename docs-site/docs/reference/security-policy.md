---
title: "Security policy"
description: "Please do not open a public issue for a security problem."
---

# Security policy

## Reporting a vulnerability

Please do not open a public issue for a security problem.

Report it privately by email to **security@padosoft.com**.

> TO CONFIRM by the maintainers: the contact address above is a placeholder and has not been confirmed as the security contact for this project. Replace it before publishing this repository.

Include:

- the package version or commit, and the PHP and Laravel versions;
- the steps to reproduce, using fake tokens and keys only;
- the impact you expect.

Do not include real device tokens, bearer tokens, receipt markers, Apple `.p8` keys, Google service-account JSON or any other secret, even to show a reproduction. Describe the shape of the data instead.

## Scope

In scope:

- the code in this repository (`src/`, `config/`, migrations and stubs once they exist);
- how the package stores, encrypts, redacts and logs tokens, keys and receipt markers (plan §8, "Riservatezza");
- the transport's TLS and host verification and the official endpoint rule (plan §4.6);
- the authentication and token cache behaviour (plan §6.2).

Out of scope:

- the Apple Push Notification service and Google FCM themselves; report those to Apple or Google;
- the security of an application that uses this package, except where the package makes an insecure choice for it.

## Rules the project follows

- Tokens, bearer tokens, receipt markers and private keys never appear in issues, logs, fixtures, documentation or test output. Value objects redact themselves in `__debugInfo`.
- Test keys are generated at runtime; no key material is committed.
- Endpoints come from a fixed set of official hosts, and TLS and certificate verification stay on.

## Supported versions

No release exists yet. Security fixes will be made on the default branch until the first release is published; this section will then list the supported versions.

---

*This page is generated from [`SECURITY.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/SECURITY.md). To change it, edit that file in the repository, not this site.*
