<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityMessage;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityType;
use Padosoft\MobilePush\Core\Apns\Message\Alert;
use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;
use Padosoft\MobilePush\Core\Apns\Message\Sound;
use Padosoft\MobilePush\Tests\Scenario\Apns\Compile\ApnsKit;

/*
 * Apple's own documented payloads (tests/Fixtures/apns, each with its source URL and retrieval date) must be
 * reproduced by the compiler from our native messages.
 */

$adventure = new class implements LiveActivityType
{
    public function swiftName(): string
    {
        return 'AdventureAttributes';
    }

    public function version(): int
    {
        return 3;
    }
};

it('records source and retrieval date in every Apple fixture', function (string $name) {
    $fixture = ApnsKit::fixture($name);

    expect($fixture['source'])->toStartWith('https://developer.apple.com/')
        ->and($fixture['retrieved'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
})->with(['alert-game-invitation', 'badge-and-sound', 'localized-alert', 'la-update-alert', 'la-start', 'la-relevance-update', 'la-broadcast-update', 'request-headers']);

it('reproduces the game-invitation alert of Apple Listing 1', function () {
    $message = ApnsMessage::alert('Game Request', 'Bob wants to play poker', 'Five Card Draw')
        ->category('GAME_INVITATION')
        ->custom('gameID', '12345678');

    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context());

    expect(ApnsKit::decode($compiled))->toEqual(ApnsKit::fixture('alert-game-invitation')['payload']);
});

it('reproduces the badge and sound payload of Apple Listing 2', function () {
    $message = ApnsMessage::alert()->badge(9)->sound('bingbong.aiff')->custom('messageID', 'ABCDEFGHIJ');

    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context());

    expect(ApnsKit::decode($compiled))->toEqual(ApnsKit::fixture('badge-and-sound')['payload']);
});

it('reproduces the localized alert of Apple Listing 3', function () {
    $message = ApnsMessage::alert()->withAlert(Alert::localized('GAME_PLAY_REQUEST_FORMAT', ['Shelly', 'Rick']));

    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::device(), ApnsKit::context());

    expect(ApnsKit::decode($compiled))->toEqual(ApnsKit::fixture('localized-alert')['payload']);
});

it('produces the headers of Apple\'s sample request', function () {
    $fixture = ApnsKit::fixture('request-headers');
    $context = ApnsKit::context(expiresAt: '@0', messageId: $fixture['headers']['apns-id'] ?? null);

    $compiled = ApnsKit::compiler()->compile(ApnsMessage::alert(body: 'Hello'), ApnsKit::device(), $context);

    expect($compiled->headers)->toEqual([
        ...$fixture['headers'],
        'apns-topic' => 'com.example.app',
    ]);
});

it('reproduces the Live Activity start payload of Apple', function () use ($adventure) {
    $payload = ApnsKit::fixture('la-start')['payload'];
    /** @var array{aps: array{attributes: array<string, mixed>, 'content-state': array<string, mixed>}} $payload */
    $message = LiveActivityMessage::start(
        $adventure,
        $payload['aps']['attributes'],
        $payload['aps']['content-state'],
        Alert::make()->withTitleLocalization('%@ is on an adventure!', ['Power Panda'])->withBodyLocalization('%@ found a sword!', ['Power Panda']),
    )->sound('chime.aiff');

    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::startToken(), ApnsKit::context('@1234'));

    expect(ApnsKit::decode($compiled))->toEqual($payload)
        ->and($compiled->headers)->toMatchArray(['apns-push-type' => 'liveactivity', 'apns-topic' => 'com.example.app.push-type.liveactivity'])
        ->and($compiled->meta['attributes_version'])->toBe('3');
});

it('reproduces the Live Activity update with alert of Apple', function () {
    $message = LiveActivityMessage::update([
        'currentHealthLevel' => 0.0,
        'eventDescription' => 'Power Panda has been knocked down!',
    ])->alert(
        Alert::make()->withTitleLocalization('%@ is knocked down!', ['Power Panda'])->withBodyLocalization('Use a potion to heal %@!', ['Power Panda']),
    )->sound(Sound::named('HeroDown.mp4'));

    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::activityToken(), ApnsKit::context('@1685952000'));

    expect(ApnsKit::decode($compiled))->toEqual(ApnsKit::fixture('la-update-alert')['payload']);
});

it('reproduces the relevance-score update of Apple', function () {
    $message = LiveActivityMessage::update(['currentHealthLevel' => 0.941, 'eventDescription' => 'Power Panda found a sword!'])
        ->relevanceScore(100);

    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::activityToken(), ApnsKit::context('@1685952000'));

    expect(ApnsKit::decode($compiled))->toEqual(ApnsKit::fixture('la-relevance-update')['payload']);
});

it('reproduces the broadcast publish of Apple, headers included', function () {
    $fixture = ApnsKit::fixture('la-broadcast-update');
    $message = LiveActivityMessage::update([
        'currentHealthLevel' => 0.0,
        'eventDescription' => 'Power Panda has been knocked down!',
    ])->alert(
        Alert::make()->withTitleLocalization('%@ is knocked down!', ['Power Panda'])->withBodyLocalization('Use a potion to heal %@!', ['Power Panda']),
    )->sound('HeroDown.mp4');

    $context = ApnsKit::context('@1685952000', messageId: $fixture['headers']['apns-request-id'] ?? null);
    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::channel(), $context);

    expect(ApnsKit::decode($compiled))->toEqual($fixture['payload'])
        ->and($compiled->headers)->toEqual($fixture['headers'])
        ->and($compiled->limitBytes)->toBe(5120);
});
