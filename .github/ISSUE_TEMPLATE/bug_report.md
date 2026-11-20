---
name: Bug report
about: Something in the package does not behave as documented
title: "[bug] "
labels: bug
---

> **Never paste device tokens, bearer tokens, receipt markers, private keys (`.p8`), Google service-account JSON or any other secret in this issue, in logs or in attachments.** Replace each one with a placeholder such as `<device-token>`, `<bearer-token>` or `<key>`. If you already pasted one, edit the issue to remove it and rotate the credential. Maintainers may delete the issue.

## What happened

A short description.

## What you expected

Point to the relevant document where you can, for example [docs/contracts/outcomes.md](../../docs/contracts/outcomes.md).

## How to reproduce

The smallest code that shows the problem. Use fake values only.

```php
// your code here
```

## Result observed

- Status (`SendStatus`), if you received one:
- Reason code (`ReasonCode`) and the raw reason, if shown. Redact anything that looks like a token.
- Exception class and message, if any:

## Environment

- Package version or commit:
- PHP version:
- Laravel version:
- Provider: APNs / FCM / both
- Execution mode: auto / live / simulate
- Delivery profile: light / durable
- Operating system and web server, if relevant:
