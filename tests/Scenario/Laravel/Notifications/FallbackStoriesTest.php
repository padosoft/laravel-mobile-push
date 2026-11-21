<?php

declare(strict_types=1);

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Padosoft\MobilePush\Core\Driver\GateDenial;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Notifications\FallbackEvaluator;
use Padosoft\MobilePush\Laravel\Notifications\FallbackPolicy;
use Padosoft\MobilePush\Laravel\Notifications\FallbackReason;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Notifications\Fixtures\OrderShipped;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Notifications\Fixtures\Shopper;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Notifications\NotificationsWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

beforeEach(function () {
    $this->world = NotificationsWorld::install();
    $this->user = Shopper::withId(1);
    $this->notification = static function (array $fallback = ['mail']): OrderShipped {
        $notification = new OrderShipped(['push'], $fallback);
        $notification->id = (string) Str::uuid(); // as Laravel assigns it once per notify() and keeps it across queue retries

        return $notification;
    };
});

it('no devices, fallback opted in: the email is sent once, and a second run of the same notification sends no second email', function () {
    $notification = ($this->notification)();

    $this->user->notify($notification);
    $this->user->notify($notification); // a retried job runs the same serialized notification again

    expect($this->world->mailCount())->toBe(1)
        ->and($this->world->fellBack)->toHaveCount(1)
        ->and($this->world->fellBack[0]->reason)->toBe(FallbackReason::NoDestination)
        ->and($this->world->push->transport->sentCount())->toBe(0);
});

it('without the opt-in nothing falls back, and the master switch and the no-destination switch are honoured', function () {
    $this->user->notify(($this->notification)([]));
    expect($this->world->mailCount())->toBe(0);

    config()->set('mobile-push.fallback.when_no_destination', false);
    $this->user->notify(($this->notification)());
    expect($this->world->mailCount())->toBe(0);

    config()->set('mobile-push.fallback.when_no_destination', true);
    config()->set('mobile-push.fallback.enabled', false);
    $this->user->notify(($this->notification)());
    expect($this->world->mailCount())->toBe(0);
});

it('uninstalled app: the only device answers 410, the email goes out once and PushFellBack tells why', function () {
    $this->world->iphone($this->user);
    $this->world->appUninstalled();
    $notification = ($this->notification)();

    $this->user->notify($notification);
    $this->user->notify($notification);

    expect($this->world->mailCount())->toBe(1)
        ->and($this->world->fellBack)->toHaveCount(1)
        ->and($this->world->fellBack[0]->channels)->toBe(['mail'])
        ->and($this->world->fellBack[0]->reason)->toBe(FallbackReason::NotAccepted)
        ->and($this->world->fellBack[0]->counts['rejected'])->toBe(1)
        ->and($this->world->fellBack[0]->notificationClass)->toBe(OrderShipped::class)
        ->and($this->world->fellBack[0]->notifiableId)->toBe('1');
});

it('the response was lost: the outcome is unknown, so no email', function () {
    $this->world->iphone($this->user);
    $this->world->push->transport->push(new TransportFailure(TransportEvidence::Unknown, 0, 'connection reset after the request was written'));
    $sent = [];
    Event::listen(NotificationSent::class, function (NotificationSent $e) use (&$sent): void {
        $sent[] = $e;
    });

    $this->user->notify(($this->notification)());

    expect($sent[0]->response->countOf(SendStatus::OutcomeUnknown))->toBe(1)
        ->and($this->world->mailCount())->toBe(0)
        ->and($this->world->fellBack)->toBeEmpty();
});

it('one of two phones accepted the push: no email', function () {
    $this->world->iphone($this->user);
    $this->world->android($this->user);
    $this->world->appUninstalled(); // the iPhone is gone, the Android phone accepts

    $this->user->notify(($this->notification)());

    expect($this->world->push->requestsTo('api.push.apple.com'))->toHaveCount(1)
        ->and($this->world->push->requestsTo('fcm.googleapis.com'))->toHaveCount(1)
        ->and($this->world->mailCount())->toBe(0);
});

it('opted out of push (consent gate) or capped: skipped on purpose, so no email', function (Closure $gate) {
    $this->world->iphone($this->user);
    MobilePush::eligibleUsing($gate);

    $this->user->notify(($this->notification)());

    expect($this->world->push->transport->sentCount())->toBe(0)
        ->and($this->world->mailCount())->toBe(0)
        ->and($this->world->fellBack)->toBeEmpty();
})->with([
    'opted out' => [fn () => false],
    'frequency capped' => [fn () => GateDenial::skip(ReasonCode::PolicyFrequencyCapped)],
]);

it('the email permission is separate: the fallback policy says no, so no email although the push was rejected', function () {
    $this->world->iphone($this->user);
    $this->world->appUninstalled();
    $asked = [];
    app(FallbackPolicy::class)->allowUsing(function (object $notifiable, $notification, string $channel) use (&$asked): bool {
        $asked[] = [$notifiable::class, $notification::class, $channel];

        return false;
    });

    $this->user->notify(($this->notification)());

    expect($asked)->toBe([[Shopper::class, OrderShipped::class, 'mail']])
        ->and($this->world->mailCount())->toBe(0)
        ->and($this->world->fellBack)->toBeEmpty();
});

it('simulated on a laptop: nothing real was attempted, so no email', function () {
    $world = $this->world;
    app()['env'] = 'local';
    config()->set('mobile-push.mode', 'auto');
    W::forgetSingletons();
    $world->iphone($this->user);

    $this->user->notify(($this->notification)());

    expect($world->push->transport->sentCount())->toBe(0)
        ->and($world->mailCount())->toBe(0);
});

it('deferred now, decided later: the light profile triggers nothing now; the evaluator with the final rejected results triggers it once', function () {
    $this->world->iphone($this->user);
    MobilePush::eligibleUsing(fn () => GateDenial::defer(ReasonCode::PolicyIneligible, $this->world->push->clock->now()->modify('+2 hours')));
    $notification = ($this->notification)();

    $this->user->notify($notification);
    expect($this->world->push->transport->sentCount())->toBe(0)
        ->and($this->world->mailCount())->toBe(0)
        ->and($this->world->fellBack)->toBeEmpty();

    $final = [new SendResult(ApnsDeviceToken::of(W::apnsToken('iphone')), SendStatus::Rejected, Reason::of(ReasonCode::DestinationUnregistered), attempts: 1)];
    $first = app(FallbackEvaluator::class)->evaluate($this->user, $notification, $final);
    $second = app(FallbackEvaluator::class)->evaluate($this->user, $notification, $final);

    expect($first->dispatched)->toBe(['mail'])
        ->and($second->dispatched)->toBe([])
        ->and($second->withheld)->toBe(['mail' => 'already_sent'])
        ->and($this->world->mailCount())->toBe(1);
});

it('canary: no device token appears in PushFellBack or in the notification handed to the mail channel', function () {
    $iphone = $this->world->iphone($this->user, 'canary-iphone');
    $android = $this->world->android($this->user, 'canary-android');
    $this->world->push->answer = static fn ($r) => new Response(
        str_contains($r->url, 'apple') ? 410 : 404,
        [],
        (string) json_encode(str_contains($r->url, 'apple') ? ['reason' => 'Unregistered'] : ['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]]),
    );
    $handedToMail = [];
    Event::listen(NotificationSending::class, function (NotificationSending $e) use (&$handedToMail): void {
        if ($e->channel === 'mail') {
            $handedToMail[] = serialize($e->notification).var_export($e->notification, true);
        }
    });

    $this->user->notify(($this->notification)());

    $everything = json_encode($this->world->fellBack).serialize($this->world->fellBack).print_r($this->world->fellBack, true).implode('', $handedToMail);
    expect($this->world->fellBack)->toHaveCount(1)
        ->and($this->world->fellBack[0]->counts['rejected'])->toBe(2)
        ->and($handedToMail)->toHaveCount(1)
        ->and($everything)->not->toContain($iphone)
        ->and($everything)->not->toContain($android);
});
