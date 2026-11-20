<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\DestinationGate;
use Padosoft\MobilePush\Core\Driver\GateContext;
use Padosoft\MobilePush\Core\Driver\GateDenial;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/** A registry-backed gate in miniature: one lookup per window, denials by position. */
final class ConsentGate implements DestinationGate
{
    /** @var list<int> */
    public array $windowSizes = [];

    /** @param  array<string, GateDenial>  $denied  destination value => denial */
    public function __construct(private readonly array $denied) {}

    public function check(GateContext $context, array $items): array
    {
        $this->windowSizes[] = count($items);
        $out = [];
        foreach ($items as $i => $item) {
            if (isset($this->denied[$item->destination->value()])) {
                $out[$i] = $this->denied[$item->destination->value()];
            }
        }

        return $out;
    }
}

/*
 * Story: two of six users withdrew marketing consent, and one reached today's frequency cap.
 */
it('skips the destinations a gate denies, with the gate reason, and never sends them', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $tokens = iterator_to_array(SendKit::apnsTokens(6), false);
    $consent = new ConsentGate([
        $tokens[1]->value() => GateDenial::skip(ReasonCode::PolicyIneligible, 'No marketing consent.'),
        $tokens[4]->value() => GateDenial::skip(ReasonCode::PolicyIneligible, 'No marketing consent.'),
    ]);
    $tomorrow = $kit->clock->now()->modify('+1 day');
    $cap = new ConsentGate([$tokens[2]->value() => GateDenial::defer(ReasonCode::PolicyFrequencyCapped, $tomorrow, 'Daily cap reached.')]);
    $options = SendKit::live(trafficClass: TrafficClass::Bulk, gates: [$consent, $cap], window: 3);

    $results = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('Sale', 'Today')->policy('marketing'), $tokens, $options));

    $sent = array_map(fn (Request $r) => SendKit::tokenOf($r), $transport->requests());
    expect($sent)->toBe([$tokens[0]->value(), $tokens[3]->value(), $tokens[5]->value()])
        ->and(array_map(fn ($r) => $r->status, $results))->toBe([
            SendStatus::Accepted, SendStatus::Skipped, SendStatus::Deferred, SendStatus::Accepted, SendStatus::Skipped, SendStatus::Accepted,
        ])
        ->and($results[1]->reason->code)->toBe(ReasonCode::PolicyIneligible)
        ->and($results[1]->reason->hint)->toBe('No marketing consent.')
        ->and($results[2]->reason->code)->toBe(ReasonCode::PolicyFrequencyCapped)
        ->and($results[2]->nextAttemptAt)->toEqual($tomorrow)
        ->and($consent->windowSizes)->toBe([3, 3])          // one call per window
        ->and($cap->windowSizes)->toBe([2, 2]);             // sees only what the previous gate let through
});
