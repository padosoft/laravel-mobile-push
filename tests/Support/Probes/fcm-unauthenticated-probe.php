<?php

/*
 * Credential-less probe of the REAL Google endpoints. Not a test (the suite never touches the network): run it by hand.
 *
 *     php tests/Support/Probes/fcm-unauthenticated-probe.php
 *
 * It sends made-up credentials through the real pipeline (CurlTransport, FcmDriver, classifier) and prints how
 * Google's real answers are classified. Nothing can be delivered: there is no valid project, token or key behind
 * these requests. Expected, as observed on 2026-11-21:
 *   - a send with a made-up bearer: HTTP 401 UNAUTHENTICATED -> auth_token_expired, one refresh, one more attempt,
 *     then deferred;
 *   - the OAuth exchange with a made-up service account: HTTP 400 invalid_grant -> AuthenticationException, and a send
 *     through that credential ends failed / auth_token_invalid with nothing sent.
 * HTTPS_PROXY and SSL_CERT_FILE are honoured when set.
 */

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Padosoft\MobilePush\Core\Auth\AccessTokenProvider;
use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Auth\InMemoryTokenStore;
use Padosoft\MobilePush\Core\Clock\SystemClock;
use Padosoft\MobilePush\Core\Driver\Sender;
use Padosoft\MobilePush\Core\Fcm\Auth\ServiceAccountCredentials;
use Padosoft\MobilePush\Core\Fcm\Auth\ServiceAccountTokenProvider;
use Padosoft\MobilePush\Core\Fcm\FcmConfig;
use Padosoft\MobilePush\Core\Fcm\FcmDriver;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Policy\SafeModeGuard;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

$ca = getenv('SSL_CERT_FILE') ?: null;
$proxy = getenv('HTTPS_PROXY') ?: null;
$clock = new SystemClock;
$transport = new CurlTransport;
$kit = new SendKit;

$bogus = new class($clock) implements AccessTokenProvider
{
    public function __construct(private SystemClock $clock) {}

    public function token(): AuthToken
    {
        return new AuthToken('ya29.this-is-not-a-real-token', $this->clock->now(), $this->clock->now()->modify('+1 hour'));
    }

    public function refreshAfterRejection(AuthToken $rejected): AuthToken
    {
        return new AuthToken('ya29.this-is-not-a-real-token-either', $this->clock->now(), $this->clock->now()->modify('+1 hour'));
    }

    public function identity(): string
    {
        return 'probe';
    }
};

$show = static function (string $title, iterable $results): void {
    echo "== {$title}\n";
    foreach ($results as $r) {
        printf("status=%s code=%s raw=%s http=%s attempts=%d credential_action=%s\n  hint=%s\n", $r->status->value, $r->reason->code->value, $r->reason->rawReason ?? '-', $r->httpStatus ?? '-', $r->attempts, $r->decision?->credentialAction->value ?? '-', $r->reason->hint ?? '-');
    }
};

// A. A send with a made-up bearer token.
$driver = new FcmDriver(new FcmConfig(SendKit::fcmBinding(), $bogus, proxy: $proxy, caBundlePath: $ca), $clock);
$sender = new Sender($transport, $kit->guards(), new SafeModeGuard($kit->index), $clock, new RetryPolicy(jitter: 0.0));
$show('FCM send with a made-up bearer token', SendKit::all($sender->send($driver, PushMessage::make('probe', 'probe'), [FcmToken::of('not-a-real-registration-token')], SendKit::live())));

// B. The OAuth exchange with a made-up service account (key generated now).
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
openssl_pkey_export($key, $pem);
$credentials = ServiceAccountCredentials::fromArray(['type' => 'service_account', 'project_id' => 'example-project', 'private_key_id' => 'probe-key', 'private_key' => $pem, 'client_email' => 'probe@example-project.iam.gserviceaccount.com']);
$profile = new ConnectionProfile('probe-oauth', proxy: $proxy, caBundlePath: $ca);
$provider = new ServiceAccountTokenProvider($credentials, $transport, new InMemoryTokenStore($clock), $clock, profile: $profile);
echo "== OAuth exchange with a made-up service account\n";
try {
    $provider->token();
    echo "UNEXPECTED: a token was issued\n";
} catch (Throwable $e) {
    printf("%s: %s\n", $e::class, $e->getMessage());
}
$driver2 = new FcmDriver(new FcmConfig(SendKit::fcmBinding(), $provider, proxy: $proxy, caBundlePath: $ca), $clock);
$show('FCM send when the OAuth exchange is refused', SendKit::all($sender->send($driver2, PushMessage::make('probe', 'probe'), [FcmToken::of('not-a-real-registration-token')], SendKit::live())));
