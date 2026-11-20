<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\AttemptReport;
use Padosoft\MobilePush\Core\Driver\GuardTransition;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Driver\SendObserver;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Tests\Scenario\Send\RecordingObserver;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

final class BrokenLogSink implements SendObserver
{
    public function attemptFinished(AttemptReport $attempt): void
    {
        throw new RuntimeException('log disk full');
    }

    public function resultProduced(SendResult $result, RetryBudget $budget, SendItem $item): void
    {
        throw new RuntimeException('log disk full');
    }

    public function tokenRefreshed(BindingRef $binding, int $credentialGeneration, string $credentialIdentity): void
    {
        throw new RuntimeException('log disk full');
    }

    public function guardTransition(GuardTransition $transition): void
    {
        throw new RuntimeException('log disk full');
    }

    public function providerThrottled(BindingRef $binding, string $accountKey, DateTimeImmutable $until): void
    {
        throw new RuntimeException('log disk full');
    }
}

function scriptedApns(): FakeTransport
{
    return (new FakeTransport)->respondUsing(fn (Request $r) => match (hexdec(substr(SendKit::tokenOf($r), -2)) % 3) {
        0 => new Response(200),
        1 => SendKit::apnsReason(400, 'BadDeviceToken'),
        default => SendKit::apnsReason(410, 'Unregistered', ['timestamp' => 1_763_600_000_000]),
    });
}

/*
 * Story: the disk holding the push log is full. Pushes must go out exactly as they would otherwise.
 */
it('produces the same results and the same requests when every observer call throws', function () {
    $control = new SendKit;
    $controlTransport = scriptedApns();
    $expected = SendKit::all($control->sender($controlTransport)->send($control->apnsDriver(window: 4), PushMessage::make('A', 'B'), SendKit::apnsTokens(10), SendKit::live()));

    $kit = new SendKit;
    $transport = scriptedApns();
    $sender = $kit->sender($transport, observers: [new BrokenLogSink]);
    $actual = SendKit::all($sender->send($kit->apnsDriver(window: 4), PushMessage::make('A', 'B'), SendKit::apnsTokens(10), SendKit::live()));

    $summary = fn (array $results) => array_map(fn (SendResult $r) => [$r->destination->value(), $r->status, $r->reason->code, $r->attempts, $r->destinationAction], $results);
    expect($summary($actual))->toBe($summary($expected))
        ->and(count($transport->requests()))->toBe(count($controlTransport->requests()))
        ->and(count($transport->requests()))->toBe(10)
        ->and($sender->observerFailures())->toBeGreaterThanOrEqual(20);   // one attempt + one result per destination at least
});

it('hands the observers only redacted diagnostics: no bearer, no device token, no body', function () {
    $kit = new SendKit;
    $observer = new RecordingObserver;
    $transport = (new FakeTransport)->push(SendKit::apnsReason(400, 'BadDeviceToken'));
    $token = SendKit::apnsToken(5);

    SendKit::all($kit->sender($transport, observers: [$observer])->send($kit->apnsDriver(), PushMessage::make('Secret title', 'Secret body'), [$token], SendKit::live(receiptMarker: fn () => 'receipt-marker-value-123')));

    $bearer = substr($transport->requests()[0]->headers['authorization'], 7);
    $dump = var_export([$observer->attempts[0]->request, $observer->attempts[0]->response], true);
    expect($observer->attempts)->toHaveCount(1)
        ->and($dump)->not->toContain($bearer)
        ->and($dump)->not->toContain($token->value())
        ->and($dump)->not->toContain('Secret body')
        ->and($dump)->not->toContain('receipt-marker-value-123')
        ->and($observer->attempts[0]->attempt)->toBe(1)
        ->and($transport->requests()[0]->body)->toContain('receipt-marker-value-123');   // the marker did travel
});
