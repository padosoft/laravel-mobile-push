<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityMessage;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityType;
use Padosoft\MobilePush\Core\Apns\Message\Alert;
use Padosoft\MobilePush\Core\Apns\Priority;
use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Tests\Scenario\Apns\Compile\ApnsKit;

final class DeliveryActivity implements LiveActivityType
{
    public function swiftName(): string
    {
        return 'DeliveryAttributes';
    }

    public function version(): int
    {
        return 2;
    }
}

function startDelivery(): LiveActivityMessage
{
    return LiveActivityMessage::start(
        new DeliveryActivity,
        ['orderId' => 'A-42'],
        ['status' => 'preparing', 'eta' => 1795180000],
        Alert::make('Delivery started', 'Your order is being prepared'),
    );
}

it('starts a Live Activity from a push-to-start token', function () {
    $compiled = ApnsKit::compiler()->compile(startDelivery(), ApnsKit::startToken(), ApnsKit::context('2026-11-20T10:00:00Z'));

    expect(ApnsKit::decode($compiled))->toEqual(['aps' => [
        'timestamp' => 1795168800,
        'event' => 'start',
        'content-state' => ['status' => 'preparing', 'eta' => 1795180000],
        'attributes-type' => 'DeliveryAttributes',
        'attributes' => ['orderId' => 'A-42'],
        'alert' => ['title' => 'Delivery started', 'body' => 'Your order is being prepared'],
    ]])->and($compiled->headers)->toBe([
        'apns-push-type' => 'liveactivity',
        'apns-topic' => 'com.example.app.push-type.liveactivity',
        'apns-priority' => '10',
    ])->and($compiled->meta)->toMatchArray(['activity_event' => 'start', 'attributes_version' => '2']);
});

it('asks the device for an update token or a channel when starting (iOS 18)', function () {
    $withToken = ApnsKit::compiler()->compile(startDelivery()->inputPushToken(), ApnsKit::startToken(), ApnsKit::context());
    $withChannel = ApnsKit::compiler()->compile(startDelivery()->inputPushChannel('dHN0LXNyY2gtY2hubA=='), ApnsKit::startToken(), ApnsKit::context());

    expect(ApnsKit::decode($withToken)['aps'])->toMatchArray(['input-push-token' => 1])
        ->and(ApnsKit::decode($withChannel)['aps'])->toMatchArray(['input-push-channel' => 'dHN0LXNyY2gtY2hubA=='])
        ->and(ApnsKit::decode($withToken)['aps'])->not->toHaveKey('input-push-channel');
});

it('encodes empty content-state and attributes as JSON objects', function () {
    $message = LiveActivityMessage::start(new DeliveryActivity, [], [], Alert::make('Hi'));

    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::startToken(), ApnsKit::context('@100'));

    expect($compiled->body)->toContain('"content-state":{}')->toContain('"attributes":{}');
});

it('updates a Live Activity with stale date, relevance and priority 5', function () {
    $message = LiveActivityMessage::update(['status' => 'on the way'], new DeliveryActivity)
        ->staleAt(new DateTimeImmutable('2026-11-20T11:00:00Z'))
        ->relevanceScore(50)
        ->priority(Priority::Normal);

    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::activityToken(), ApnsKit::context('2026-11-20T10:00:00Z'));

    expect(ApnsKit::decode($compiled))->toEqual(['aps' => [
        'timestamp' => 1795168800,
        'event' => 'update',
        'content-state' => ['status' => 'on the way'],
        'stale-date' => 1795172400,
        'relevance-score' => 50,
    ]])->and($compiled->headers['apns-priority'])->toBe('5');
});

it('ends a Live Activity with the final state and a dismissal date', function () {
    $message = LiveActivityMessage::end(['status' => 'delivered'])->dismissAt(new DateTimeImmutable('2026-11-20T10:30:00Z'));

    $compiled = ApnsKit::compiler()->compile($message, ApnsKit::activityToken(), ApnsKit::context('2026-11-20T10:00:00Z'));

    expect(ApnsKit::decode($compiled)['aps'])->toEqual([
        'timestamp' => 1795168800,
        'event' => 'end',
        'content-state' => ['status' => 'delivered'],
        'dismissal-date' => 1795170600,
    ]);
});

it('takes the timestamp from the compile context at every attempt', function () {
    $message = LiveActivityMessage::update(['n' => 1]);

    $first = ApnsKit::compiler()->compile($message, ApnsKit::activityToken(), ApnsKit::context('2026-11-20T10:00:00Z'));
    $retry = ApnsKit::compiler()->compile($message, ApnsKit::activityToken(), ApnsKit::context('2026-11-20T10:00:09Z'));

    expect(ApnsKit::decode($first)['aps']['timestamp'])->toBe(1795168800)
        ->and(ApnsKit::decode($retry)['aps']['timestamp'])->toBe(1795168809);
});

it('publishes an update on a broadcast channel with a mandatory expiration', function () {
    $update = LiveActivityMessage::update(['score' => '2-1']);

    $unstored = ApnsKit::compiler()->compile($update, ApnsKit::channel(), ApnsKit::context());
    $stored = ApnsKit::compiler()->compile($update, ApnsKit::channel(), ApnsKit::context(expiresAt: '2026-11-20T12:00:00Z'));

    expect($unstored->headers['apns-expiration'])->toBe('0')
        ->and($stored->headers['apns-expiration'])->toBe('1795176000')
        ->and($stored->headers)->not->toHaveKey('apns-topic');
});

it('forces expiration 0 on a channel that stores no messages and warns', function () {
    $update = LiveActivityMessage::update(['score' => '2-1'])->channelStoresNoMessages();

    $compiled = ApnsKit::compiler()->compile($update, ApnsKit::channel(), ApnsKit::context(expiresAt: '2026-11-20T12:00:00Z'));

    expect($compiled->headers['apns-expiration'])->toBe('0')->and($compiled->warnings)->toHaveCount(1);
});

it('uses the 5120 byte limit for a broadcast and 4096 for a device token', function () {
    $update = LiveActivityMessage::update(['n' => 1]);

    expect(ApnsKit::compiler()->compile($update, ApnsKit::channel(), ApnsKit::context())->limitBytes)->toBe(5120)
        ->and(ApnsKit::compiler()->compile($update, ApnsKit::activityToken(), ApnsKit::context())->limitBytes)->toBe(4096);
});

it('refuses to start a Live Activity from a broadcast channel', function () {
    ApnsKit::compiler()->compile(startDelivery(), ApnsKit::channel(), ApnsKit::context());
})->throws(InvalidDestinationException::class, 'broadcast channel can never start');

it('refuses a start sent to an update token and an update sent to a push-to-start token', function () {
    expect(fn () => ApnsKit::compiler()->compile(startDelivery(), ApnsKit::activityToken(), ApnsKit::context()))
        ->toThrow(InvalidDestinationException::class)
        ->and(fn () => ApnsKit::compiler()->compile(LiveActivityMessage::update(['a' => 1]), ApnsKit::startToken(), ApnsKit::context()))
        ->toThrow(InvalidDestinationException::class)
        ->and(fn () => ApnsKit::compiler()->compile(LiveActivityMessage::end(), ApnsKit::device(), ApnsKit::context()))
        ->toThrow(InvalidDestinationException::class);
});

it('refuses a start without an alert or without a Swift type name', function () {
    $noAlert = LiveActivityMessage::start(new DeliveryActivity, [], [], new Alert);
    $blankType = LiveActivityMessage::start(new class implements LiveActivityType
    {
        public function swiftName(): string
        {
            return ' ';
        }

        public function version(): int
        {
            return 1;
        }
    }, [], [], Alert::make('x'));

    expect(fn () => ApnsKit::compiler()->compile($noAlert, ApnsKit::startToken(), ApnsKit::context()))
        ->toThrow(InvalidMessageException::class, 'alert')
        ->and(fn () => ApnsKit::compiler()->compile($blankType, ApnsKit::startToken(), ApnsKit::context()))
        ->toThrow(InvalidMessageException::class, 'Swift');
});

it('refuses options that do not belong to the operation', function () {
    $context = ApnsKit::context();

    expect(fn () => ApnsKit::compiler()->compile(LiveActivityMessage::update(['a' => 1])->inputPushToken(), ApnsKit::activityToken(), $context))
        ->toThrow(InvalidMessageException::class, 'only apply when starting')
        ->and(fn () => ApnsKit::compiler()->compile(LiveActivityMessage::update(['a' => 1])->dismissAt(new DateTimeImmutable('@5')), ApnsKit::activityToken(), $context))
        ->toThrow(InvalidMessageException::class, 'end')
        ->and(fn () => ApnsKit::compiler()->compile(LiveActivityMessage::update(['a' => 1])->priority(Priority::Low), ApnsKit::activityToken(), $context))
        ->toThrow(InvalidMessageException::class, 'priority 10 or 5')
        ->and(fn () => ApnsKit::compiler()->compile(startDelivery()->inputPushToken()->inputPushChannel('abc'), ApnsKit::startToken(), $context))
        ->toThrow(InvalidMessageException::class, 'not both');
});
