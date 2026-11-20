<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;
use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Identity\Destination\FcmFid;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\CompileContext;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Tests\Scenario\Apns\Compile\ApnsKit;

dataset('wrong destinations', [
    'alert to voip token' => [fn () => ApnsMessage::alert('x'), fn () => ApnsKit::voipToken()],
    'alert to start token' => [fn () => ApnsMessage::alert('x'), fn () => ApnsKit::startToken()],
    'alert to activity token' => [fn () => ApnsMessage::alert('x'), fn () => ApnsKit::activityToken()],
    'alert to broadcast channel' => [fn () => ApnsMessage::alert('x'), fn () => ApnsKit::channel()],
    'background to broadcast channel' => [fn () => ApnsMessage::background(), fn () => ApnsKit::channel()],
    'voip to device token' => [fn () => ApnsMessage::voip(), fn () => ApnsKit::device()],
    'unified to voip token' => [fn () => PushMessage::make('x'), fn () => ApnsKit::voipToken()],
]);

it('rejects a destination kind that does not match the operation', function (Closure $message, Closure $destination) {
    ApnsKit::compiler()->compile($message(), $destination(), ApnsKit::context());
})->with('wrong destinations')->throws(InvalidDestinationException::class);

it('rejects every FCM destination', function () {
    $fcm = [
        FcmToken::of(str_repeat('a', 152)),
        FcmFid::of('c3JzgWvRTkGI2A0FfXq1Zr'),
        FcmTopic::of('news'),
    ];

    foreach ($fcm as $destination) {
        expect(fn () => ApnsKit::compiler()->compile(PushMessage::make('x'), $destination, ApnsKit::context()))
            ->toThrow(InvalidDestinationException::class, 'FCM');
    }
});

it('rejects a context whose binding is not APNs', function () {
    $context = new CompileContext(ApnsKit::binding(Provider::Fcm), new DateTimeImmutable('2026-11-20T10:00:00Z'));

    ApnsKit::compiler()->compile(PushMessage::make('x'), ApnsKit::device(), $context);
})->throws(InvalidDestinationException::class, 'not an APNs binding');

it('supports exactly the three APNs message types', function () {
    $compiler = ApnsKit::compiler();

    expect($compiler->supports(PushMessage::make('x')))->toBeTrue()
        ->and($compiler->supports(ApnsMessage::alert('x')))->toBeTrue()
        ->and($compiler->supports(Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityMessage::end()))->toBeTrue()
        ->and($compiler->supports(new class implements Padosoft\MobilePush\Core\Message\Message {}))->toBeFalse();
});
