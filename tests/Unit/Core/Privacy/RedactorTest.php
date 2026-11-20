<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;

$tok = str_repeat('ab12', 16);

it('redacts the device token in an APNs path, also inside text', function () use ($tok) {
    $r = new Redactor;

    expect($r->url("https://api.push.apple.com/3/device/{$tok}"))->toBe('https://api.push.apple.com/3/device/[redacted]')
        ->and($r->string("POST /3/device/{$tok} failed"))->toBe('POST /3/device/[redacted] failed');
});

it('redacts sensitive headers and leaves others', function () {
    $out = (new Redactor)->headers([
        'authorization' => 'bearer abc.def.ghi', 'apns-channel-id' => 'dHN0LXNyY2g=', 'cookie' => 'a=b',
        'proxy-authorization' => 'Basic xyz', 'apns-priority' => '10', 'content-type' => 'application/json',
    ]);

    expect($out)->toBe([
        'authorization' => '[redacted]', 'apns-channel-id' => '[redacted]', 'cookie' => '[redacted]',
        'proxy-authorization' => '[redacted]', 'apns-priority' => '10', 'content-type' => 'application/json',
    ]);
});

it('redacts bearer values, JWTs, PEM blocks and credential JSON fields in free text', function () {
    $r = new Redactor;
    $jwt = 'eyJhbGciOiJFUzI1NiJ9.eyJpc3MiOiJhYmMifQ.c2lnbmF0dXJl';

    expect($r->string("Authorization: Bearer {$jwt}"))->not->toContain('eyJ')
        ->and($r->string("token {$jwt} here"))->toBe('token [redacted:jwt] here')
        ->and($r->string("x -----BEGIN PRIVATE KEY-----\nMIIabc\n-----END PRIVATE KEY----- y"))->toBe('x [redacted:key] y')
        ->and($r->string('{"access_token":"ya29.SECRET","expires_in":3600}'))->toBe('{"access_token":"[redacted]","expires_in":3600}')
        ->and($r->string('https://h/x?access_token=SECRET&a=1'))->toBe('https://h/x?access_token=[redacted]&a=1');
});

it('redacts runtime secrets and their encoded forms', function () {
    $r = (new Redactor)->withSecrets(['RAW/SECRET+VALUE=1', "line-one-secret-xxxx\nline-two-secret-yyyy"]);

    expect($r->string('a RAW/SECRET+VALUE=1 b'))->not->toContain('SECRET+VALUE')
        ->and($r->string(rawurlencode('RAW/SECRET+VALUE=1')))->not->toContain('SECRET')
        ->and($r->string('{"k":"RAW\/SECRET+VALUE=1"}'))->not->toContain('SECRET')
        ->and($r->string(base64_encode('RAW/SECRET+VALUE=1')))->not->toContain(base64_encode('RAW'))
        ->and($r->string('only line-two-secret-yyyy leaked'))->not->toContain('yyyy');
});

it('redacts provider messages that echo a token', function () use ($tok) {
    $msg = "The registration token {$tok}:APA91bOpaque is not a valid FCM registration token";

    expect((new Redactor)->providerMessage($msg))->toBe('The registration token [redacted:token] is not a valid FCM registration token');
});

it('walks nested structures, keys and throwables', function () use ($tok) {
    $r = (new Redactor)->withSecrets(['canary-secret-1']);
    $out = $r->value([
        'error' => ['message' => "bad token {$tok}", 'status' => 'INVALID_ARGUMENT'],
        'device_token' => 'whatever', 'list' => ['x canary-secret-1 y', 3, null, true],
        'ex' => new RuntimeException('boom canary-secret-1'), 'obj' => new stdClass,
    ]);

    $json = json_encode($out, JSON_THROW_ON_ERROR);
    expect($json)->not->toContain($tok)->not->toContain('canary-secret-1')->not->toContain('whatever')
        ->and($out['error']['status'])->toBe('INVALID_ARGUMENT')
        ->and($out['obj'])->toBe('[stdClass]');
});

it('builds safe arrays for requests and responses', function () use ($tok) {
    $r = new Redactor;
    $req = $r->request(new Request('1', 'POST', "https://api.push.apple.com/3/device/{$tok}", ['authorization' => 'bearer zzzzzz'], '{"aps":{"alert":"private text"}}'));
    $res = $r->response(new Response(400, ['x' => 'y'], '{"error":{"message":"token '.$tok.' invalid"}}'));

    expect(json_encode([$req, $res]))->not->toContain($tok)->not->toContain('zzzzzz')->not->toContain('private text')
        ->and($req['body_bytes'])->toBe(strlen('{"aps":{"alert":"private text"}}'));
});

it('is idempotent', function () use ($tok) {
    $r = (new Redactor)->withSecrets(['secret', 'redacted', 'canary-xyz-123']);
    $input = [
        "https://h/3/device/{$tok}?token=abc", 'Bearer abcdef123456', 'canary-xyz-123 and secret',
        'k' => ['message' => "echo {$tok}"], 'authorization' => 'x',
    ];
    $once = $r->value($input);

    expect($r->value($once))->toBe($once)
        ->and($r->providerMessage($r->providerMessage("a {$tok}")))->toBe($r->providerMessage("a {$tok}"));
});
