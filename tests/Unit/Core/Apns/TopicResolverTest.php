<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\PushType;
use Padosoft\MobilePush\Core\Apns\TopicResolver;
use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;

it('appends the documented suffix to the bundle id', function (PushType $type, string $expected) {
    expect(TopicResolver::resolve('com.example.app', $type, null))->toBe($expected);
})->with([
    [PushType::Alert, 'com.example.app'],
    [PushType::Background, 'com.example.app'],
    [PushType::LiveActivity, 'com.example.app.push-type.liveactivity'],
    [PushType::Voip, 'com.example.app.voip'],
    [PushType::Complication, 'com.example.app.complication'],
    [PushType::FileProvider, 'com.example.app.pushkit.fileprovider'],
    [PushType::Location, 'com.example.app.location-query'],
    [PushType::PushToTalk, 'com.example.app.voip-ptt'],
    [PushType::Widgets, 'com.example.app.push-type.widgets'],
    [PushType::Controls, 'com.example.app.push-type.controls'],
    [PushType::Accessory, 'com.example.app.push-type.accessory'],
]);

it('covers all twelve push types', function () {
    expect(PushType::cases())->toHaveCount(12);
});

it('lets an explicit topic win over the derivation', function () {
    expect(TopicResolver::resolve('com.example.app', PushType::Voip, 'com.example.custom'))->toBe('com.example.custom');
});

it('requires an explicit topic for mdm', function () {
    expect(TopicResolver::resolve('com.example.app', PushType::Mdm, 'com.apple.mgmt.External.abc'))->toBe('com.apple.mgmt.External.abc')
        ->and(fn () => TopicResolver::resolve('com.example.app', PushType::Mdm))->toThrow(InvalidMessageException::class);
});

it('refuses an empty or unsafe topic', function (string $topic) {
    TopicResolver::resolve('com.example.app', PushType::Alert, $topic);
})->with(['', 'a b', "a\r\nb", 'é'])->throws(InvalidMessageException::class);

it('refuses an empty bundle id', function () {
    TopicResolver::resolve('', PushType::Alert);
})->throws(InvalidMessageException::class);
