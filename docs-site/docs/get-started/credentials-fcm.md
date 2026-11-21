---
title: "FCM credentials"
description: "How to give the package the right to send through Firebase Cloud Messaging HTTP v1. One FCM connection = one Firebase project. FCM has a single environment (the binding's environment is always production)."
---

# FCM credentials

How to give the package the right to send through Firebase Cloud Messaging HTTP v1. One FCM connection = one Firebase project. FCM has a single environment (the binding's environment is always `production`).

Google's documentation: [Authorize send requests](https://firebase.google.com/docs/cloud-messaging/auth-server) and [Application Default Credentials](https://cloud.google.com/docs/authentication/application-default-credentials). Console menus change over time; the steps below describe what to look for.

## Option 1: a service-account JSON key (default)

1. In the Firebase console, open your project's settings and the **Service accounts** tab, and generate a new private key. You download a JSON file containing `type: "service_account"`, `project_id`, `private_key_id`, `private_key` and `client_email`.
2. Store the file in your secret manager. Anyone who has it can send pushes to all your users.
3. Configure the connection:

```php
// config/mobile-push.php
'connections' => [
    'android' => [
        'driver' => 'fcm',
        'auth' => 'service_account',             // default
        'credentials' => [
            'path' => env('MOBILE_PUSH_FCM_CREDENTIALS_PATH'),    // set exactly ONE of the sources
            'base64' => env('MOBILE_PUSH_FCM_CREDENTIALS_BASE64'),
        ],
        'project_id' => env('MOBILE_PUSH_FCM_PROJECT_ID'),        // null = the project_id of the JSON
    ],
],
```

`credentials` is a secret source exactly like the APNs key: `path`, `inline` (the JSON text), `base64` or `resolver` + `reference` (a `Padosoft\MobilePush\Core\Auth\SecretResolver` class). See [credentials-apns.md](/get-started/credentials-apns#apns-credentials-where-to-put-the-key-secret-sources).

What the package does with it: it signs a JWT assertion with the service account's RSA key (RS256), exchanges it at `https://oauth2.googleapis.com/token` for an access token with the `https://www.googleapis.com/auth/firebase.messaging` scope, caches the access token in the shared cache (encrypted) and renews it before it expires, one process at a time. On a 401 from FCM it refreshes once and retries.

### Least privilege

The file Firebase generates belongs to the project's Firebase Admin SDK service account, which has broad rights on the project. If you can, create a **dedicated service account** in the Google Cloud console that can only send messages, and generate its key instead. Google's IAM documentation lists the predefined role for sending FCM messages; the role and permission names have **not been verified from this project** (Google's sites were not reachable when this page was written), so check the IAM reference for Firebase Cloud Messaging before granting.

### Why `token_uri` in the file is ignored

A service-account file contains a `token_uri`. The package **never uses it**: the assertion (signed with your private key) is always sent to Google's fixed endpoint `https://oauth2.googleapis.com/token`. A file whose `token_uri` points elsewhere (a tampered or unusual file) would otherwise make the package hand a signed credential to another host. When the file's value differs, `doctor` and `credentials:check` report a warning (`fcm.service_account.token_uri_ignored`). If you really need another token endpoint, use Application Default Credentials (below), which go through Google's own library.

Local checks on the file: it must have `type: "service_account"` (authorized-user and external-account files need the ADC option), every required field, and an RSA key of at least 2048 bits.

## Option 2: Application Default Credentials (`google/auth`)

On Google Cloud (Cloud Run, GKE, Compute Engine) you can avoid key files: the attached service account, Workload Identity Federation or `GOOGLE_APPLICATION_CREDENTIALS` provide the credentials. This needs the optional `google/auth` package:

```bash
composer require google/auth
```

```php
'android' => [
    'driver' => 'fcm',
    'auth' => 'adc',
    'project_id' => env('MOBILE_PUSH_FCM_PROJECT_ID'),   // required with adc
],
```

The package asks `Google\Auth\ApplicationDefaultCredentials` for a credential with the FCM scope and caches its tokens like the service-account ones. Without `google/auth` installed, or when no default credentials are found, the connection fails with a `ConfigurationException` that says what to do. ADC has only been tested in this project through the library's interfaces, not on Google Cloud.

This is also what to use when the key must not be exportable: unlike APNs (see the `signer` key in [APNs credentials](/get-started/credentials-apns#apns-credentials-where-to-put-the-key-secret-sources-keeping-the-key-in-a-kmshsm)), an FCM connection has no custom `signer` setting, so a KMS/HSM-held Google key is reached through the attached service account or Workload Identity Federation, not through the package's own signing.

## Secondary credential and rotation

```php
'android' => [
    // ... primary as above ...
    'secondary' => [
        'credentials' => ['base64' => env('MOBILE_PUSH_FCM_SECONDARY_CREDENTIALS_BASE64')],
    ],
],
```

- The secondary must be a service account of the **same project**; another project is a `ConfigurationException` when the connection is first used. It is a rotation twin, never a fallback to another project.
- When the primary reaches the authentication breaker's threshold (`guards.auth.threshold`, default 5 consecutive credential failures), the breaker switches to the secondary instead of opening, once, and a `CredentialFailedOver` event is dispatched.
- To rotate a key: generate a new key for the service account, deploy it as `secondary`, check with `mobile-push:doctor --live`, promote it to primary, then delete the old key in the Google Cloud console.

## Checking the credentials

`php artisan mobile-push:doctor` (offline):

```
Connection "android"
  ✓ The service-account file is complete and its key is RSA >= 2048.
  ✓ fcm: my-shop-app (production, service account push-sender@my-shop-app.iam.gserviceaccount.com, key id abc123), generation ef2621d1b20d.
```

`php artisan mobile-push:doctor --live` makes one real **`validate_only`** call per FCM connection: Google authenticates and validates the request, and nothing is delivered. An authentication error is reported as a failure. `php artisan mobile-push:credentials:check` lists the same offline findings per connection (exit 0 ok, 1 warnings, 2 errors; `--json`, `--no-alert`).

To test a real device token without delivering anything, use `php artisan mobile-push:send --platform=android --stdin --validate-only --title=Test`: the result is `validated` when Google accepts the request. It is not a delivery test.

Next: back to [getting started](/get-started/getting-started).

## What was checked against Google's real endpoints

Nothing in this project has run with a real Firebase project or service account. Three things were checked against Google's real servers on 2026-11-21, none of which needs credentials or can deliver anything. They were run from the build environment (through its HTTPS proxy) and from a GitHub-hosted runner, which connects to Google directly, with the same results:

- **A send with a made-up bearer token.** `fcm.googleapis.com` answered HTTP 401 with `{"error": {"code": 401, "status": "UNAUTHENTICATED", ...}}`. The package classified it as `auth_token_expired`, refreshed the token once, tried once more, and returned `deferred`: the behaviour described in [retries and limits](/reliability/retries-and-limits) for an access token the provider refuses.
- **The OAuth exchange with a made-up service account** (a key generated on the spot). `oauth2.googleapis.com/token` answered HTTP 400 `invalid_grant`; the provider raised an `AuthenticationException` naming the status and the error code (never the assertion), and a send through that credential ended `failed` / `auth_token_invalid` with zero requests to FCM.
- **The Discovery document.** The live `https://fcm.googleapis.com/$discovery/rest?version=v1` (revision `20261112`) is identical to the snapshot the message builders and the tests are pinned to.

You can repeat the first two with `php tests/Support/Probes/fcm-unauthenticated-probe.php` from a clone of the repository, and the third with `php tests/Support/Probes/fcm-discovery-drift.php`. The `providers` GitHub workflow runs all three every week and on demand; when the Discovery document changes it fails and opens an issue with the summary. Everything past authentication (a real token accepted, `UNREGISTERED`, quota answers, topic subscriptions, `validate_only` with a valid credential) is still verified against fixtures only.

---

*This page is generated from [`docs/credentials-fcm.md`](https://github.com/padosoft/laravel-mobile-push/blob/main/docs/credentials-fcm.md). To change it, edit that file in the repository, not this site.*
