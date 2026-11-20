<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Contracts\QueuedSender;
use Padosoft\MobilePush\Laravel\Contracts\QueueOptions;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

it('explains that queued sending needs the queue module when no QueuedSender is bound', function () {
    $world = W::install(['connections' => ['ios' => W::apns()]]);
    unset(app()[QueuedSender::class]);   // as in an application without the queue module

    expect(fn () => MobilePush::to(ApnsDeviceToken::of(W::apnsToken('q')))->queue(PushMessage::make('Hi')))
        ->toThrow(ConfigurationException::class, 'no Padosoft\MobilePush\Laravel\Contracts\QueuedSender is bound');
    expect($world->transport->sentCount())->toBe(0);
});

it('hands each connection of a multi-device user to the QueuedSender with the queue options and one effect key', function () {
    $world = W::install(['connections' => ['ios' => W::apns(), 'android' => W::fcm()]]);
    $queued = new class implements QueuedSender
    {
        /** @var list<array{binding: BindingRef, count: int, options: SendOptions, queue: QueueOptions}> */
        public array $calls = [];

        public function queue(BindingRef $binding, Message $message, iterable $destinations, SendOptions $options, QueueOptions $queue): string
        {
            $this->calls[] = ['binding' => $binding, 'count' => count(iterator_to_array($destinations, false)), 'options' => $options, 'queue' => $queue];

            return 'effect-'.count($this->calls);
        }
    };
    app()->instance(QueuedSender::class, $queued);

    $id = MobilePush::to([ApnsDeviceToken::of(W::apnsToken('q')), FcmToken::of('fake-android-registration-token-q')])
        ->bulk()
        ->onQueueConnection('redis')->onQueue('push')->delay(30)
        ->queue(PushMessage::make('Later'));

    expect($id)->toBe('effect-1,effect-2')
        ->and($world->transport->sentCount())->toBe(0)
        ->and(array_map(fn ($c) => $c['binding']->name, $queued->calls))->toBe(['ios', 'android'])
        ->and($queued->calls[0]['queue']->queueConnection)->toBe('redis')
        ->and($queued->calls[0]['queue']->queue)->toBe('push')
        ->and($queued->calls[0]['queue']->delay)->toBe(30)
        ->and($queued->calls[0]['options']->trafficClass)->toBe(TrafficClass::Bulk)
        ->and($queued->calls[0]['options']->effectKey)->not->toBeNull()
        ->and($queued->calls[0]['options']->effectKey)->toBe($queued->calls[1]['options']->effectKey);
});
