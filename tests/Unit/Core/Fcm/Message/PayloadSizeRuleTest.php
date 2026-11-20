<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Fcm\FcmPayloadLimits;
use Padosoft\MobilePush\Core\Identity\DestinationKind;

$fixture = json_decode((string) file_get_contents(__DIR__.'/../../../../Fixtures/fcm/payload-size-rule.json'), true, 512, JSON_THROW_ON_ERROR);

dataset('size rule cases', array_combine(
    array_column($fixture['cases'], 'name'),
    array_map(fn (array $case) => [$case['message'], $case['bytes']], $fixture['cases']),
));

it('counts payload bytes exactly as the pinned fixture says', function (array $message, int $bytes) {
    expect(FcmPayloadLimits::measure($message))->toBe($bytes);
})->with('size rule cases');

it('uses the pinned limits per destination kind', function () use ($fixture) {
    expect(FcmPayloadLimits::limitFor(DestinationKind::FcmToken))->toBe($fixture['limits']['default'])
        ->and(FcmPayloadLimits::limitFor(DestinationKind::FcmFid))->toBe($fixture['limits']['default'])
        ->and(FcmPayloadLimits::limitFor(DestinationKind::FcmTopic))->toBe($fixture['limits']['topic'])
        ->and(FcmPayloadLimits::limitFor(DestinationKind::FcmCondition))->toBe($fixture['limits']['condition']);
});
