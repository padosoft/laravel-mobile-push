<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support\LoggingFixtures as F;

function logPayload(string $level): ?string
{
    F::migrate();
    F::configure('database', ['payload' => $level]);
    F::manager()->recordResult(F::binding(), F::result(F::token(1)), ['payload' => [
        'aps' => ['alert' => ['title' => 'Hello Mario', 'body' => 'Your order shipped'], 'sound' => 'default'],
        'order_id' => 'A-123',
        'device_token' => 'must-not-appear-1234567890',
        'items' => [['sku' => 'S1'], ['sku' => 'S2']],
    ]]);
    F::manager()->flush();

    $row = DB::table('mobile_push_logs')->first();

    return $row->payload;
}

/** Payload levels. */
it('writes no payload at level none (the default)', function () {
    expect(logPayload('none'))->toBeNull();
});

it('writes only the key names at level keys', function () {
    $payload = logPayload('keys');

    expect(json_decode((string) $payload, true))->toBe(['aps.alert.title', 'aps.alert.body', 'aps.sound', 'order_id', 'device_token', 'items.sku'])
        ->and($payload)->not->toContain('Hello Mario')
        ->and($payload)->not->toContain('A-123');
});

it('writes the redacted payload at level full', function () {
    $payload = json_decode((string) logPayload('full'), true);

    expect($payload['aps']['alert']['title'])->toBe('Hello Mario')
        ->and($payload['order_id'])->toBe('A-123')
        ->and($payload['device_token'])->toBe('[redacted]');
});
