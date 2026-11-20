<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;

it('Canary markers never survive the Redactor, in any serialisation', function () {
    $token = 'c4n4ry0d3v1c3'.str_repeat('f0', 24);
    $bearer = 'CANARYBEARERvalue1234567890';
    $receipt = 'canary-receipt-marker-9f8e7d';
    $keyLine = 'MIIEvQIBADANBgkqhkiG9w0BAQEFAASCCANARYKEYLINE0123456789abcdef';
    $markers = [$token, $bearer, $receipt, $keyLine];

    $request = new Request('r1', 'POST', "https://api.push.apple.com/3/device/{$token}", [
        'authorization' => "bearer {$bearer}", 'apns-channel-id' => $receipt, 'apns-topic' => 'com.example.App',
    ], json_encode(['aps' => ['alert' => 'hi'], 'receipt' => $receipt], JSON_THROW_ON_ERROR));

    $response = new Response(400, ['apns-id' => 'x', 'set-cookie' => "s={$receipt}"], json_encode(['reason' => 'BadDeviceToken', 'token' => $token], JSON_THROW_ON_ERROR));

    $fcmBody = json_encode(['error' => [
        'code' => 400, 'status' => 'INVALID_ARGUMENT',
        'message' => "The registration token {$token} is not a valid FCM registration token. Authorization: Bearer {$bearer}",
    ]], JSON_THROW_ON_ERROR);
    $fcmResponse = new Response(400, [], $fcmBody);

    $exception = new RuntimeException("Send failed for {$token} with Bearer {$bearer}; receipt {$receipt}; key -----BEGIN PRIVATE KEY-----\n{$keyLine}\n-----END PRIVATE KEY-----", 0, new LogicException("inner {$keyLine}"));

    // Unregistered markers: structural rules alone must catch tokens in paths, bearers, JSON fields and PEM blocks.
    // Registered markers: the rest (receipts, bare key lines) are removed wherever they occur.
    $redactor = (new Redactor)->withSecrets([$receipt, $keyLine, $bearer, $token]);

    $safe = $redactor->value([
        'request' => $request, 'response' => $response, 'fcm' => $fcmResponse, 'exception' => $exception,
        'context' => ['nested' => ['deep' => "x {$token} y", 'key' => $keyLine]],
    ]);

    $encoded = [json_encode($safe, JSON_THROW_ON_ERROR), call_user_func('print_r', $safe, true), var_export($safe, true)];

    foreach ($encoded as $dump) {
        foreach ($markers as $marker) {
            expect($dump)->not->toContain($marker);
        }
    }

    // Structural rules without any registered secret still remove the token from the path and the bearer value.
    $bare = new Redactor;
    expect(json_encode($bare->request($request), JSON_THROW_ON_ERROR))->not->toContain($token)->not->toContain($bearer)
        ->and(json_encode($bare->response($fcmResponse), JSON_THROW_ON_ERROR))->not->toContain($token)->not->toContain($bearer)
        ->and(json_encode($bare->throwable($exception), JSON_THROW_ON_ERROR))->not->toContain($token)->not->toContain($bearer);
});
