<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Driver\TransmitObserver;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Tests\Scenario\Send\RecordingObserver;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Stories about several per-call observers, only some of which guard the moment of transmission: an audit log,
 * the durable relay's lease check, a second relay. The transport is the oracle of what left.
 */

/** A lease checker: withholds the listed delivery ids, and may answer with request ids it was never given. */
final class LeaseChecker extends RecordingObserver implements TransmitObserver
{
    /** @var list<list<string>> */
    public array $asked = [];

    /**
     * @param  list<string>  $lost  delivery ids whose lease was lost
     * @param  list<string>  $extra  request ids answered besides those
     */
    public function __construct(private readonly array $lost, private readonly array $extra = []) {}

    public function beforeTransmit(array $items): array
    {
        $this->asked[] = array_values(array_map(static fn (SendItem $i): string => (string) $i->deliveryId, $items));
        $withheld = $this->extra;
        foreach ($items as $requestId => $item) {
            if (in_array($item->deliveryId, $this->lost, true)) {
                $withheld[] = $requestId;
            }
        }

        return $withheld;
    }
}

/** @return list<SendItem> */
function leasedItems(int $n): array
{
    $items = [];
    for ($i = 1; $i <= $n; $i++) {
        $items[] = new SendItem(SendKit::apnsToken($i), null, 'd'.$i);
    }

    return $items;
}

it('asks the lease checker even when a plain observer was registered before it', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $audit = new RecordingObserver;
    $lease = new LeaseChecker(['d2']);

    $results = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('A', 'B'), leasedItems(3), SendKit::live()->withObserver($audit)->withObserver($lease)));

    expect(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Accepted, SendStatus::Skipped, SendStatus::Accepted])
        ->and($results[1]->reason->code)->toBe(ReasonCode::Cancelled)
        ->and($transport->requests())->toHaveCount(2)
        ->and($audit->results)->toHaveCount(3);
});

it('does not ask a second lease checker when the first one withheld every request of the pass', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $first = new LeaseChecker(['d1', 'd2']);
    $second = new LeaseChecker([]);

    $results = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('A', 'B'), leasedItems(2), SendKit::live()->withObserver($first)->withObserver($second)));

    expect(array_map(fn ($r) => $r->status, $results))->each->toBe(SendStatus::Skipped)
        ->and($transport->requests())->toBe([])
        ->and($first->asked)->toBe([['d1', 'd2']])
        ->and($second->asked)->toBe([]);
});

it('asks a second lease checker only about the requests the first one let through', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $first = new LeaseChecker(['d1']);
    $second = new LeaseChecker(['d3']);

    $results = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('A', 'B'), leasedItems(3), SendKit::live()->withObserver($first)->withObserver($second)));

    expect(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Skipped, SendStatus::Accepted, SendStatus::Skipped])
        ->and($second->asked)->toBe([['d2', 'd3']])
        ->and(array_map(fn (Request $r) => SendKit::tokenOf($r), $transport->requests()))->toBe([SendKit::apnsToken(2)->value()]);
});

it('ignores request ids it never handed out and still withholds the ones that are real', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $lease = new LeaseChecker(['d2'], extra: ['no-such-request']);

    $results = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('A', 'B'), leasedItems(3), SendKit::live()->withObserver($lease)));

    expect(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Accepted, SendStatus::Skipped, SendStatus::Accepted])
        ->and($results[1]->deliveryId)->toBe('d2')
        ->and($transport->requests())->toHaveCount(2);
});
