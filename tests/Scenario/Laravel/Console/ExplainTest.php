<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\Outcome\ApnsReasonCatalog;
use Padosoft\MobilePush\Core\Fcm\Outcome\FcmReasonCatalog;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Laravel\Console\Support\ExplainCatalog;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Console\ConsoleWorld as C;

it('explains BadDeviceToken: cause, remedy and what the package does', function () {
    [$code, $out] = C::run('mobile-push:explain', ['reason' => 'BadDeviceToken']);

    expect($code)->toBe(0)
        ->and($out)->toContain('APNs reason: BadDeviceToken (HTTP 400)')
        ->toContain('sandbox vs production')
        ->toContain('destination_rejected')
        ->toContain('Quarantines the registration')
        ->toContain('Does not retry');
});

it('accepts a lenient spelling and explains retries and credential reactions', function () {
    [, $out] = C::run('mobile-push:explain', ['reason' => 'expired-provider-token']);

    expect($out)->toContain('Refreshes the provider token once')->toContain('Retries within the retry budget');

    [, $fcm] = C::run('mobile-push:explain', ['reason' => 'UNREGISTERED', '--provider' => 'fcm']);
    expect($fcm)->toContain('FCM error code: UNREGISTERED')->toContain('Invalidates the registration');
});

it('prints the whole catalog as valid JSON with every APNs reason, FCM code and package reason code', function () {
    [$code, $out] = C::run('mobile-push:explain', ['--all' => true, '--json' => true]);
    $data = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
    $keys = array_map(static fn (array $e): string => $e['provider'].'|'.$e['reason'], $data['entries']);

    expect($code)->toBe(0)->and($data['count'])->toBe(count($data['entries']));
    foreach (array_keys(ApnsReasonCatalog::all()) as $raw) {
        expect($keys)->toContain('apns|'.$raw);
    }
    foreach ([...array_keys(FcmReasonCatalog::errorCodes()), ...array_keys(FcmReasonCatalog::statuses())] as $raw) {
        expect($keys)->toContain('fcm|'.$raw);
    }
    foreach (ReasonCode::cases() as $reason) {
        expect($keys)->toContain('package|'.$reason->value);
    }
});

it('prints the catalog as a table, filtered by provider', function () {
    [$code, $out] = C::run('mobile-push:explain', ['--all' => true, '--provider' => 'fcm']);

    expect($code)->toBe(0)->and($out)->toContain('QUOTA_EXCEEDED')->not->toContain('BadDeviceToken');
});

it('suggests the closest matches for an unknown reason', function () {
    [$code, $out] = C::run('mobile-push:explain', ['reason' => 'BadDevicToken']);

    expect($code)->toBe(1)->and($out)->toContain('not a reason the package knows')->toContain('BadDeviceToken');
});

it('has a text for every package reason code', function () {
    $texts = array_filter(ExplainCatalog::all(), static fn ($e): bool => $e->provider === 'package');

    expect($texts)->toHaveCount(count(ReasonCode::cases()));
    foreach ($texts as $entry) {
        expect($entry->meaning)->not->toBe('')->and($entry->behaviour)->not->toBe([]);
    }
});

it('needs a reason or --all', function () {
    [$code] = C::run('mobile-push:explain');

    expect($code)->toBe(2);
});
