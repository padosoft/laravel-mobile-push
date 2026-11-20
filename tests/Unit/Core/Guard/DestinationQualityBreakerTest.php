<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Guard\BreakerTransition as T;
use Padosoft\MobilePush\Core\Guard\DestinationQualityBreaker;
use Padosoft\MobilePush\Core\Guard\InMemoryGuardStore;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Outcome\Decision;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Tests\Support\FakeClock;

function qualityBreaker(FakeClock $clock): DestinationQualityBreaker
{
    return new DestinationQualityBreaker(new InMemoryGuardStore($clock), $clock, minSample: 100, threshold: 0.5, cooldownSeconds: 60, probeSize: 10);
}

it('does not open below the minimum sample whatever the ratio', function () {
    $b = qualityBreaker(new FakeClock);

    expect($b->record('b', 99, 99))->toBe(T::None)->and($b->isOpen('b'))->toBeFalse();
});

it('opens once the sample is large enough and the ratio is strictly above the threshold', function () {
    $b = qualityBreaker(new FakeClock);
    $b->record('b', 50, 25);

    expect($b->record('b', 50, 25))->toBe(T::None)       // exactly 0.5: not above
        ->and($b->record('b', 10, 10))->toBe(T::Opened);
});

it('gates only bulk traffic while open, then probes a bounded batch', function () {
    $clock = new FakeClock;
    $b = qualityBreaker($clock);
    $b->record('b', 100, 100);

    expect($b->allow('b', 500, TrafficClass::Bulk)[0])->toBe(0)
        ->and($b->allow('b', 500, TrafficClass::Transactional)[0])->toBe(500);

    $clock->advance(60);
    [$granted, $transition] = $b->allow('b', 500, TrafficClass::Bulk);
    expect($granted)->toBe(10)->and($transition)->toBe(T::HalfOpened)
        ->and($b->allow('b', 500, TrafficClass::Bulk)[0])->toBe(0);

    expect($b->record('b', 10, 1))->toBe(T::Closed)
        ->and($b->allow('b', 500, TrafficClass::Bulk)[0])->toBe(500);
});

it('reopens when the probe batch is mostly bad', function () {
    $clock = new FakeClock;
    $b = qualityBreaker($clock);
    $b->record('b', 100, 100);
    $clock->advance(60);
    $b->allow('b', 10, TrafficClass::Bulk);

    expect($b->record('b', 6, 6))->toBe(T::Reopened)->and($b->allow('b', 10, TrafficClass::Bulk)[0])->toBe(0);
});

it('counts quarantine as bad and ignores invalidations and non destination outcomes', function () {
    $d = static fn (SendStatus $s, DestinationAction $a = DestinationAction::None, bool $throttle = false) => new Decision($s, Reason::none(), destinationAction: $a, throttleProvider: $throttle);

    expect(DestinationQualityBreaker::classify($d(SendStatus::Rejected, DestinationAction::Quarantine)))->toBe(1)
        ->and(DestinationQualityBreaker::classify($d(SendStatus::Rejected, DestinationAction::Invalidate)))->toBeNull()
        ->and(DestinationQualityBreaker::classify($d(SendStatus::Accepted)))->toBe(0)
        ->and(DestinationQualityBreaker::classify($d(SendStatus::Failed, throttle: true)))->toBeNull()
        ->and(DestinationQualityBreaker::classify($d(SendStatus::OutcomeUnknown)))->toBeNull();
});
