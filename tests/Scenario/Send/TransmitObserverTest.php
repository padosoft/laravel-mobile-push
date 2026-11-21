<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\AttemptReport;
use Padosoft\MobilePush\Core\Driver\GuardTransition;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Driver\SendObserver;
use Padosoft\MobilePush\Core\Driver\TransmitObserver;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * "Tell me when the requests leave": a per-call observer that also implements TransmitObserver is asked once per
 * pass, right before the transport, with exactly the requests about to be written; it can withhold some (the durable
 * relay does when a lease was lost). The transport is the oracle.
 */

final class StartRecorder implements SendObserver, TransmitObserver
{
    /** @var list<list<string>> delivery ids per call */
    public array $calls = [];

    /** @var list<int> transport requests counted when each call happened */
    public array $sentBefore = [];

    /** @param  list<string>  $withhold  delivery ids to withhold */
    public function __construct(private readonly FakeTransport $transport, private readonly array $withhold = [], private readonly bool $explode = false) {}

    public function beforeTransmit(array $items): array
    {
        if ($this->explode) {
            throw new RuntimeException('the ledger is down: the start cannot be recorded');
        }
        $this->calls[] = array_values(array_map(static fn (SendItem $i): string => (string) $i->deliveryId, $items));
        $this->sentBefore[] = $this->transport->sentCount();
        $withheld = [];
        foreach ($items as $requestId => $item) {
            if (in_array($item->deliveryId, $this->withhold, true)) {
                $withheld[] = $requestId;
            }
        }

        return $withheld;
    }

    public function attemptFinished(AttemptReport $attempt): void {}

    public function resultProduced(SendResult $result, RetryBudget $budget, SendItem $item): void {}

    public function tokenRefreshed(BindingRef $binding, int $credentialGeneration, string $credentialIdentity): void {}

    public function guardTransition(GuardTransition $transition): void {}

    public function providerThrottled(BindingRef $binding, string $accountKey, DateTimeImmutable $until): void {}
}

/** @return Generator<int, SendItem> */
function itemsWithIds(int $n): Generator
{
    for ($i = 1; $i <= $n; $i++) {
        yield new SendItem(SendKit::apnsToken($i), null, 'd'.$i);
    }
}

it('is asked once per window, right before the requests leave, with exactly those requests', function (): void {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $recorder = new StartRecorder($transport);

    $report = $kit->sender($transport)->send($kit->apnsDriver(window: 3), PushMessage::make('Hi', 'There'), itemsWithIds(7), SendKit::live()->withObserver($recorder));
    $statuses = array_map(static fn (SendResult $r): string => $r->status->value, iterator_to_array($report->results(), false));

    expect($recorder->calls)->toBe([['d1', 'd2', 'd3'], ['d4', 'd5', 'd6'], ['d7']])
        ->and($recorder->sentBefore)->toBe([0, 3, 6])            // nothing of the window had been written yet
        ->and($transport->sentCount())->toBe(7)
        ->and(array_values(array_unique($statuses)))->toBe(['accepted']);
});

it('a withheld request never reaches the transport and comes back Skipped(Cancelled), the others are sent', function (): void {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $recorder = new StartRecorder($transport, ['d2']);

    $results = iterator_to_array($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('Hi', 'There'), itemsWithIds(3), SendKit::live()->withObserver($recorder))->results(), false);
    $tokens = array_map(static fn (Request $r): string => SendKit::tokenOf($r), $transport->requests());

    expect($transport->sentCount())->toBe(2)
        ->and($tokens)->not->toContain(SendKit::apnsToken(2)->value())
        ->and($results[1]->deliveryId)->toBe('d2')
        ->and($results[1]->status)->toBe(SendStatus::Skipped)
        ->and($results[1]->reason->code)->toBe(ReasonCode::Cancelled)
        ->and($results[1]->attempts)->toBe(0)
        ->and([$results[0]->status, $results[2]->status])->toBe([SendStatus::Accepted, SendStatus::Accepted]);
});

it('an observer that cannot record the start stops the send before anything of the pass is written', function (): void {
    $kit = new SendKit;
    $transport = new FakeTransport;

    expect(fn () => $kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('Hi', 'There'), itemsWithIds(3), SendKit::live()->withObserver(new StartRecorder($transport, explode: true))))
        ->toThrow(RuntimeException::class, 'the start cannot be recorded')
        ->and($transport->sentCount())->toBe(0);
});
