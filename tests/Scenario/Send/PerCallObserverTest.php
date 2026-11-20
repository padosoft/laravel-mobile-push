<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Driver\SendObserver;
use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Tests\Scenario\Send\RecordingObserver;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Story: the durable relay cannot reach the Sender constructor, so it passes its own observer with the call and
 * persists each result with its final budget and item before acknowledging the job.
 */
it('hands every result, with its budget and item, to an observer passed with the call', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->push(SendKit::apnsReason(503, 'ServiceUnavailable'))->defaultTo(new Response(200));
    $relay = new RecordingObserver;
    $own = new RecordingObserver;
    $driver = $kit->apnsDriver();
    $message = PushMessage::make('Receipt', 'Thanks')->ttl(3600);

    $results = SendKit::all($kit->sender($transport, observers: [$own])->send(
        $driver, $message, [SendKit::apnsToken(1), new SendItem(SendKit::apnsToken(2), null, 'delivery-2')],
        SendKit::live()->withObserver($relay),
    ));

    expect($results[0]->status)->toBe(SendStatus::Deferred)
        ->and($results[1]->status)->toBe(SendStatus::Accepted)
        ->and($relay->results)->toHaveCount(2)
        ->and($relay->results[0]['budget']->attempts)->toBe(1)
        ->and($relay->results[1]['item']->deliveryId)->toBe('delivery-2')
        ->and(array_map(fn ($r) => $r['result']->status, $relay->results))->toBe(array_map(fn ($r) => $r['result']->status, $own->results))
        ->and($relay->attempts)->toHaveCount(count($own->attempts));

    // Resumed after the provider pause: the relay passes the stored budget back and sees the continued one.
    $kit->clock->advance(20 * 60);
    $stored = RetryBudget::fromArray($relay->results[0]['budget']->toArray());
    $resumeObserver = new RecordingObserver;
    [$resumed] = SendKit::all($kit->sender($transport)->send(
        $driver, $message, [new SendItem(SendKit::apnsToken(1), $stored, 'delivery-1')],
        SendKit::live(observers: [$resumeObserver]),
    ));

    expect($resumed->status)->toBe(SendStatus::Accepted)
        ->and($resumeObserver->results)->toHaveCount(1)
        ->and($resumeObserver->results[0]['budget']->attempts)->toBe(2)
        ->and($resumeObserver->results[0]['item']->deliveryId)->toBe('delivery-1');
});

it('ignores a per-call observer that throws: results are unchanged and the failure is counted', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->defaultTo(new Response(200));
    $broken = new class extends RecordingObserver
    {
        public function resultProduced(SendResult $result, RetryBudget $budget, SendItem $item): void
        {
            throw new RuntimeException('relay storage down');
        }
    };
    $sender = $kit->sender($transport);

    $results = SendKit::all($sender->send($kit->apnsDriver(), PushMessage::make('A', 'B'), SendKit::apnsTokens(3), SendKit::live()->withObserver($broken)));

    expect(array_map(fn ($r) => $r->status, $results))->each->toBe(SendStatus::Accepted)
        ->and($transport->requests())->toHaveCount(3)
        ->and($sender->observerFailures())->toBe(3);
});
