<?php

/*
 * Credential-less probe of the REAL APNs sandbox endpoint. Not a test (the suite never touches the network): run it
 * by hand.
 *
 *     php tests/Support/Probes/apns-unauthenticated-probe.php
 *
 * It sends, through the real pipeline (CurlTransport, ApnsDriver, classifier), one push to a device token of 64 zeros
 * with a provider token signed by a P-256 key generated on the spot, for a made-up team and key id. Apple cannot
 * know that key, so nothing can be delivered: the point is to see Apple's real refusal and how it is classified.
 * A second request uses a provider token issued two hours ago (Apple refuses tokens older than one hour).
 * HTTPS_PROXY and SSL_CERT_FILE are honoured when set.
 */

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Padosoft\MobilePush\Core\Apns\ApnsConfig;
use Padosoft\MobilePush\Core\Apns\ApnsCredential;
use Padosoft\MobilePush\Core\Apns\ApnsDriver;
use Padosoft\MobilePush\Core\Apns\Auth\ApnsTokenProvider;
use Padosoft\MobilePush\Core\Auth\InMemoryTokenStore;
use Padosoft\MobilePush\Core\Clock\SystemClock;
use Padosoft\MobilePush\Core\Driver\Sender;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Policy\SafeModeGuard;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;
use Padosoft\MobilePush\Tests\Support\FakeClock;
use Psr\Clock\ClockInterface;

$ca = getenv('SSL_CERT_FILE') ?: null;
$proxy = getenv('HTTPS_PROXY') ?: null;
$clock = new SystemClock;
$kit = new SendKit;
$binding = SendKit::apnsBinding(environment: Environment::Sandbox);

$probe = static function (string $title, string $keyId, ClockInterface $tokenClock) use ($kit, $clock, $binding, $proxy, $ca): void {
    $provider = new ApnsTokenProvider($kit->signer($keyId), SendKit::TEAM, new InMemoryTokenStore($tokenClock), $tokenClock, 'default', jitterSeconds: 0);
    $driver = new ApnsDriver(new ApnsConfig($binding, ApnsCredential::token($provider, SendKit::TEAM, $keyId), proxy: $proxy, caBundlePath: $ca));
    $sender = new Sender(new CurlTransport, $kit->guards(), new SafeModeGuard($kit->index), $clock, new RetryPolicy(jitter: 0.0));

    echo "== {$title}\n";
    foreach (SendKit::all($sender->send($driver, PushMessage::make('probe', 'probe'), [ApnsDeviceToken::of(str_repeat('0', 64))], SendKit::live())) as $r) {
        printf("status=%s code=%s raw=%s http=%s attempts=%d destination_action=%s\n  hint=%s\n", $r->status->value, $r->reason->code->value, $r->reason->rawReason ?? '-', $r->httpStatus ?? '-', $r->attempts, $r->destinationAction->value, $r->reason->hint ?? '-');
    }
};

$probe('APNs sandbox, provider token signed by a key Apple does not know', 'KEYPROBE01', $clock);
$probe('APNs sandbox, provider token issued two hours ago (same made-up key)', 'KEYPROBE02', new FakeClock($clock->now()->modify('-2 hours')->format(DATE_ATOM)));
