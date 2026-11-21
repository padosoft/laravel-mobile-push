<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\Sender;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Policy\SafeModeGuard;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Tests\Scenario\Send\RecordingObserver;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;
use Psr\Log\AbstractLogger;

/*
 * The Sender's own housekeeping, seen from outside: the default inline wait really waits, a failing observer is
 * logged with what an operator needs, and the identity of a result says only what is true of it.
 */

final class ObserverFailureLog extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

final class ThrowingResultSink extends RecordingObserver
{
    public function resultProduced(SendResult $result, RetryBudget $budget, SendItem $item): void
    {
        throw new LengthException('log partition full');
    }
}

it('really waits the inline backoff with the default sleeper', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->push(new TransportFailure(TransportEvidence::NotSent, 7, 'connect() refused', false), new Response(200));
    $sender = new Sender($transport, $kit->guards(), new SafeModeGuard($kit->index), $kit->clock, new RetryPolicy(backoffBaseMs: 30, jitter: 0.0, inlineThresholdMs: 30));

    $started = hrtime(true);
    [$result] = SendKit::all($sender->send($kit->apnsDriver(), PushMessage::make('A', 'B'), [SendKit::apnsToken(1)], SendKit::live()));
    $elapsedMs = (hrtime(true) - $started) / 1e6;

    expect($result->status)->toBe(SendStatus::Accepted)
        ->and($transport->requests())->toHaveCount(2)
        ->and($elapsedMs)->toBeGreaterThanOrEqual(30.0);
});

it('logs every failing observer call with the observer and the exception class, and counts it', function () {
    $kit = new SendKit;
    $log = new ObserverFailureLog;
    $sender = new Sender(new FakeTransport, $kit->guards(), new SafeModeGuard($kit->index), $kit->clock, new RetryPolicy(jitter: 0.0), [new ThrowingResultSink], logger: $log);

    SendKit::all($sender->send($kit->apnsDriver(), PushMessage::make('A', 'B'), SendKit::apnsTokens(2), SendKit::live()));

    expect($sender->observerFailures())->toBe(2)
        ->and($log->records)->toHaveCount(2)
        ->and($log->records[0]['level'])->toBe('warning')
        ->and($log->records[0]['context'])->toBe(['observer' => ThrowingResultSink::class, 'exception' => LengthException::class]);
});

it('starts counting observer failures from zero for a sender derived with withObserver', function () {
    $kit = new SendKit;
    $sender = $kit->sender(new FakeTransport, observers: [new ThrowingResultSink]);
    SendKit::all($sender->send($kit->apnsDriver(), PushMessage::make('A', 'B'), SendKit::apnsTokens(2), SendKit::live()));

    $derived = $sender->withObserver(new RecordingObserver);

    expect($sender->observerFailures())->toBe(2)
        ->and($derived->observerFailures())->toBe(0);
    SendKit::all($derived->send($kit->apnsDriver(), PushMessage::make('A', 'B'), SendKit::apnsTokens(1), SendKit::live()));
    expect($derived->observerFailures())->toBe(1)
        ->and($sender->observerFailures())->toBe(2);
});

it('gives a simulated result no provider message id and no credential generation: nothing was sent', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;

    [$result] = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('A', 'B'), [SendKit::apnsToken(1)], new SendOptions(applicationEnvironment: 'local')));

    expect($result->status)->toBe(SendStatus::Simulated)
        ->and($transport->requests())->toBe([])
        ->and($result->providerMessageId)->toBeNull()
        ->and($result->meta)->toHaveKey('message_id')
        ->and($result->meta)->not->toHaveKey('credential_generation');
});

/*
 * Story: an FCM response is lost. Unlike APNs, FCM requests carry no id chosen by the sender, so the result has no
 * provider message id to offer: inventing one would send support looking for a message Google never named.
 */
it('gives a lost FCM response no provider message id, while a lost APNs response keeps the apns-id that was sent', function () {
    $kit = new SendKit;
    $lost = new TransportFailure(TransportEvidence::Unknown, 92, 'stream reset');
    $fcm = (new FakeTransport)->push($lost);
    $apns = (new FakeTransport)->push($lost);

    [$fcmResult] = SendKit::all($kit->sender($fcm)->send($kit->fcmDriver(), PushMessage::make('A', 'B'), [SendKit::fcmToken(1)], SendKit::live()));
    [$apnsResult] = SendKit::all($kit->sender($apns)->send($kit->apnsDriver(), PushMessage::make('A', 'B'), [SendKit::apnsToken(1)], SendKit::live()));

    expect($fcmResult->status)->toBe(SendStatus::OutcomeUnknown)
        ->and($fcmResult->providerMessageId)->toBeNull()
        ->and($fcmResult->meta['credential_generation'])->toBe('0')
        ->and($apnsResult->status)->toBe(SendStatus::OutcomeUnknown)
        ->and($apnsResult->providerMessageId)->toBe($apns->requests()[0]->headers['apns-id']);
});
