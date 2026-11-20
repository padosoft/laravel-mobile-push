<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;

it('Index key rotation: old rows are still found, new writes use the new version', function () {
    $k1 = random_bytes(32);
    $device = FcmToken::of('fcm-registration-token-xyz');

    $stored = (new TokenIndex(new KeyRing([1 => $k1])))->index($device, 'tenant');
    expect(TokenIndex::versionOf($stored))->toBe(1);

    $rotated = new TokenIndex(new KeyRing([1 => $k1, 2 => random_bytes(32)]));

    expect($rotated->candidates($device, 'tenant'))->toContain($stored)->toHaveCount(2)
        ->and($rotated->matches($stored, $device, 'tenant'))->toBeTrue()
        ->and(TokenIndex::versionOf($rotated->index($device, 'tenant')))->toBe(2)
        ->and($rotated->index($device, 'tenant'))->not->toBe($stored)
        ->and($rotated->matches($stored, $device, 'other-tenant'))->toBeFalse();
});
