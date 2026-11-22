# Comparison with other PHP push libraries

This page compares `padosoft/laravel-mobile-push` with the libraries a Laravel developer usually considers. It lists **capabilities read in each library's source code**, pinned to a tag, a commit and a file, so you can check every cell yourself. It makes no performance claim and quotes no number: measurements (made against a local HTTP/2 mock server, with the hardware and method stated there, including any comparison run against another library) are in [`benchmarks/RESULTS.md`](../benchmarks/RESULTS.md).

The sources were read on 2026-11-21 at these refs:

| Library | Ref (tag, commit) | What it is |
|---|---|---|
| [edamov/pushok](https://github.com/edamov/pushok) | `0.19.1`, `34e97101ab4e` | APNs client (no framework) |
| [laravel-notification-channels/apn](https://github.com/laravel-notification-channels/apn) | `v6.5.0`, `b792e0564523` | Laravel notification channel for APNs, built on pushok |
| [laravel-notification-channels/fcm](https://github.com/laravel-notification-channels/fcm) | `6.2.0`, `8fd8601614b5` | Laravel notification channel for FCM, built on kreait |
| [kreait/firebase-php](https://github.com/kreait/firebase-php) | `8.6.0`, `e6900e5b0b80` | Firebase Admin SDK for PHP (messaging and much more) |
| [M2mobi/ApnsPHP](https://github.com/M2mobi/ApnsPHP) (`m2mobi/apns-php`) | `4.0.0`, `75e82a58625f` | Maintained fork of the original ApnsPHP (`duccio/ApnsPHP`, now archived as `immobiliare/ApnsPHP`, whose last tags still use the legacy binary protocol) |

"This package" cells describe `padosoft/laravel-mobile-push` as it is in this repository today (how it is verified: see the [README](../README.md#project-status)). A cell says "not checked" when the fact could not be established from the source with confidence.

## What these libraries do well

Before the table, the fair part:

- **pushok** is small, has no framework dependency, has been used in production by many projects for years, and covers the APNs push types including Live Activity updates. If you need to send APNs pushes from a plain PHP script, it is far fewer moving parts than this package.
- **laravel-notification-channels/apn** and **/fcm** fit the Laravel notification system with almost no configuration and are maintained by the Laravel community. For an app that sends a handful of notifications and treats push as best effort, they are the simplest choice.
- **kreait/firebase-php** is the reference Firebase SDK for PHP: messaging is one part of a library that also covers Auth, Realtime Database, Firestore, Storage, Remote Config and App Check, with a long track record. If you already use Firebase for more than push, you likely want it anyway.
- **ApnsPHP** has a long history and optional payload auto-truncation, and its maintained fork supports token authentication and Live Activities.

This package is larger than all of them: it brings its own HTTP/2 transport, a retry planner, breakers, an optional ledger, a device registry and more. Choose it when those properties matter to you (see the table); choose a smaller library when they do not.

## APNs

| Capability | This package | pushok 0.19.1 | notification-channels/apn v6.5.0 | ApnsPHP (M2mobi) 4.0.0 |
|---|---|---|---|---|
| HTTP/2 provider API | yes | yes (`src/Request.php`, `CURL_HTTP_VERSION_2`) | yes, through pushok | yes (`ApnsPHP/Push.php`, `CURL_HTTP_VERSION_2_0`) |
| Several requests multiplexed on one connection | yes, sliding window (`transport.window`, default 100) | yes, `curl_multi`, 20 concurrent requests by default (`src/Client.php` `$nbConcurrentRequests`) | yes, through pushok | no: one `curl_exec()` at a time on one handle (`ApnsPHP/Push.php` `httpSend()`) |
| Connection kept open between sends in a long-lived worker | yes, one pool per credential identity, closed after `max_idle_seconds` | only with `setAutoCloseConnections(false)`; the default closes after every `push()` (`src/Client.php` `$autoCloseConnections = true`) | the channel uses pushok's default and calls `push()` once per notifiable (`src/ApnChannel.php`, `src/ApnServiceProvider.php`) | yes while the `Push` object lives (connection opened by `connect()`) |
| Provider token renewed automatically | yes: after 45 min, never more than every 20 min, one renewer at a time, shared through the cache | no: the JWT is built once in `Token::create()`; the app must create a new `Token` (`src/AuthProvider/Token.php`) | yes, coarse: the token is cached for 20 minutes and a new one is built afterwards (`src/ApnServiceProvider.php` `TOKEN_CACHE_MINUTES`) | no: built once in `connect()` (`ApnsPHP/Push.php`, line 419) |
| JWT algorithm header | `ES256` | `ES512` (`src/AuthProvider/Token.php`, lines 197 and 224) | as pushok | `ES256` via `lcobucci/jwt` `Ecdsa\Sha256` (`ApnsPHP/Push.php`) |
| Request body protected from libcurl's automatic re-send ([transport.md](transport.md)) | yes: bodies are streamed, a re-send fails instead of duplicating | body in memory with `CURLOPT_POSTFIELDS` (`src/Request.php`, line 76); whether duplicates occur depends on libcurl and on the moment the connection is lost. Observed once, on the local mock with libcurl 8.5.0 (`benchmarks/RESULTS.md`, "Behaviour"): a push processed by the server on a reused connection that was then dropped came back as status 200 and the server had processed it twice | as pushok | body in memory with `CURLOPT_POSTFIELDS` (`ApnsPHP/Push.php` `httpSend()`); **not tested** |
| A transport error on one request | that destination is `OutcomeUnknown` (or `Failed` with proof it was not sent); the other results are kept | `push()` throws `\Exception` when a handle has HTTP status 0, and the responses collected so far are not returned (`src/Client.php`, line 161) | as pushok | status 0 is recorded and on the next run the message is removed from the queue as having "no error" (`ApnsPHP/Push.php`, line 506) |
| Explicit "unknown outcome" state | yes (`SendStatus::OutcomeUnknown`) | no | no | no |
| Automatic retry of 429 / 5xx | yes, one budget per destination, honours Apple's 15-minute rule for 5xx, long waits become `Deferred` | no retry code in `src/` | no | yes, up to `$sendRetryTimes = 3` for statuses above 413 (`ApnsPHP/Push.php`, line 150 and `send()`) |
| Payload over 4 KB | rejected by default; opt-in truncation of title/subtitle/body on grapheme boundaries | not checked | not checked | auto-adjust (truncation of the text) on by default (`ApnsPHP/Message.php` `$autoAdjustLongPayload = true`) |
| Live Activity update/end | yes | yes: `liveactivity` push type and `content-state` (`src/AuthProvider/Token.php`, `src/Payload.php`) | yes (`src/ApnLiveActivityMessage.php`) | yes (`ApnsPHP/Message/LiveActivity.php`) |
| Live Activity broadcast channels (iOS 18) | yes: create/list/delete/sync channels and publish | no (no channel or broadcast code in `src/`) | no | no (no channel or broadcast code found) |
| Typed destinations, platform and environment never inferred | yes | no (tokens are strings) | no | no |
| Safe mode outside production (simulation by default) | yes | no | no | no |
| Testing fakes for host applications | yes (`MobilePush::fake()`, `fakeTransport()`) | not checked | Laravel's `Notification::fake()` applies | not checked |
| Framework | Laravel 12–13 | none | Laravel 12–13 (`composer.json`) | none |

## FCM

| Capability | This package | notification-channels/fcm 6.2.0 | kreait/firebase-php 8.6.0 |
|---|---|---|---|
| FCM HTTP v1 | yes | yes, through kreait | yes (`src/Messaging/RequestFactory.php`) |
| HTTP/2 | yes (default; `http_version` `1.1` available) | through kreait | when libcurl supports it (`src/Messaging/RequestFactory.php`, line 36) |
| Concurrent sends | yes, multiplexed window | `sendMulticast()` in chunks of 500 tokens (`src/FcmChannel.php`) | yes, Guzzle `Pool` (`src/Messaging/ApiClient.php`, line 36) |
| OAuth access token renewed automatically | yes | through kreait | yes, `google/auth` `AuthTokenMiddleware` + `FetchAuthTokenCache` (`src/Factory.php`) |
| Application Default Credentials | yes, with `google/auth` installed | through kreait | yes (`src/Factory.php`) |
| `validate_only` | yes (`Validated` status) | through kreait | yes (`Messaging::validate()`, `sendAll($messages, true)`) |
| Targets | token, FID, topic, condition | token | token, topic, condition (`src/Messaging/MessageTarget.php`); FID not found |
| `android_v2` block | yes | not checked | not found in `src/` |
| Topic subscription management | `topicSubscriptions` API of the Discovery document (`mobile-push:fcm:topic-subscription`) | through kreait | Instance ID endpoints `/iid/v1:batchAdd` and `batchRemove` (`src/Messaging/AppInstanceApiClient.php`) |
| Automatic retry of 429 / 5xx, honouring `Retry-After` | yes | no | no retry code found in `src/Messaging*` and `src/Http` |
| Explicit "unknown outcome" state | yes | no | no: a `SendReport` is success or failure (`src/Messaging/SendReport.php`) |
| Firebase services beyond messaging | no | no | yes (Auth, Database, Firestore, Storage, Remote Config, App Check) |

## Reading the table

- A "yes" in the column of this package means the code exists and is covered by the package's tests against a mock server, **not** that it was verified against Apple or Google (see the project status).
- A "no" for another library means the capability was not found in the source at that ref; it may exist in a later version.
- Corrections are welcome: open an issue with the file and the ref.

If you are moving from one of these libraries, see [migrating-from-pushok.md](migrating-from-pushok.md) and [migrating-from-laravel-notification-channels-apn.md](migrating-from-laravel-notification-channels-apn.md).
