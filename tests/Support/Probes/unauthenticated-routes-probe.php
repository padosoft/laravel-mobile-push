<?php

/*
 * Credential-less probe of provider ROUTES the package uses besides plain sends. Not a test (the suite never touches
 * the network): run by the `providers` workflow, or by hand.
 *
 *     php tests/Support/Probes/unauthenticated-routes-probe.php
 *
 * Every request carries a provider token signed by a key generated on the spot (Apple) or a made-up bearer (Google),
 * for made-up application ids: nothing can be created, published or delivered. The point is what answers at each
 * host: a refusal of the CREDENTIAL (403 InvalidProviderToken, 401 UNAUTHENTICATED) shows the host is there and
 * knows the route; a refusal of the PATH, a failed name resolution or a refused connection shows it is not.
 *
 *  - Apple, broadcast publish: Apple's page gives two different hosts for `/4/broadcasts/apps/{bundle}` (the
 *    standard APNs host, and `api-broadcast...` in its sample). Both are asked, in both environments.
 *  - Apple, channel management: `api-manage-broadcast...` on ports 2195 (sandbox) and 2196 (production).
 *  - Google, topic subscriptions: `projects.registrations.topicSubscriptions` on fcm.googleapis.com.
 *
 * One line per request: `<label> | <host:port> | dns=<ok|failed> http=<version> status=<code> reason=<text>`.
 */

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Padosoft\MobilePush\Core\Auth\JwtEncoder;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

$kit = new SendKit;
$jwt = (new JwtEncoder)->encode(['typ' => 'JWT', 'kid' => 'KEYPROBE03'], ['iss' => SendKit::TEAM, 'iat' => time()], $kit->signer('KEYPROBE03'));
$bundle = 'com.example.MobilePushProbe';
$channel = base64_encode(random_bytes(16));

/** @param list<string> $headers */
$ask = static function (string $label, string $method, string $url, array $headers, ?string $body): void {
    $parts = parse_url($url);
    $host = (string) ($parts['host'] ?? '');
    $port = (int) ($parts['port'] ?? 443);
    $resolved = gethostbynamel($host);
    if ($resolved === false) {
        printf("%s | %s:%d | dns=failed http=- status=- reason=name not resolved\n", $label, $host, $port);

        return;
    }
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }
    $answer = curl_exec($handle);
    if ($answer === false) {
        printf("%s | %s:%d | dns=ok http=- status=- reason=%s\n", $label, $host, $port, curl_error($handle));
        curl_close($handle);

        return;
    }
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $version = match ((int) curl_getinfo($handle, CURLINFO_HTTP_VERSION)) {
        CURL_HTTP_VERSION_2_0, CURL_HTTP_VERSION_2 => '2',
        CURL_HTTP_VERSION_1_1 => '1.1',
        default => '?',
    };
    curl_close($handle);
    $decoded = json_decode((string) $answer, true);
    $reason = is_array($decoded)
        ? (string) ($decoded['reason'] ?? $decoded['error']['status'] ?? $decoded['error']['message'] ?? '-')
        : substr(preg_replace('/\s+/', ' ', (string) $answer) ?? '', 0, 80);
    printf("%s | %s:%d | dns=ok http=%s status=%d reason=%s\n", $label, $host, $port, $version, $status, $reason === '' ? '-' : $reason);
};

$apns = ['authorization: bearer '.$jwt, 'content-type: application/json'];
$publish = array_merge($apns, ['apns-push-type: liveactivity', 'apns-channel-id: '.$channel, 'apns-priority: 5', 'apns-expiration: 0']);
$payload = '{"aps":{"timestamp":'.time().',"event":"update","content-state":{}}}';

foreach (['sandbox' => '.sandbox', 'production' => ''] as $environment => $infix) {
    $ask("apns broadcast publish, standard host ({$environment})", 'POST', "https://api{$infix}.push.apple.com/4/broadcasts/apps/{$bundle}", $publish, $payload);
    $ask("apns broadcast publish, api-broadcast host ({$environment})", 'POST', "https://api-broadcast{$infix}.push.apple.com/4/broadcasts/apps/{$bundle}", $publish, $payload);
    $port = $environment === 'sandbox' ? 2195 : 2196;
    $ask("apns channel management ({$environment})", 'GET', "https://api-manage-broadcast{$infix}.push.apple.com:{$port}/1/apps/{$bundle}/all-channels", $apns, null);
}

$ask('fcm topic subscriptions (list)', 'GET', 'https://fcm.googleapis.com/v1/projects/mobile-push-probe/registrations/probe-registration/topicSubscriptions', ['authorization: Bearer made-up-token'], null);
$ask('fcm route that does not exist (control)', 'GET', 'https://fcm.googleapis.com/v1/projects/mobile-push-probe/registrations/probe-registration/noSuchCollection', ['authorization: Bearer made-up-token'], null);
