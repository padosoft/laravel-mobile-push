<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Ledger\DeliveryState;
use Padosoft\MobilePush\Core\Ledger\LedgerSettings;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Ledger\DatabaseLedgerWorld;
use Padosoft\MobilePush\Tests\Scenario\Ledger\LedgerHarness as H;

/*
 * "A million destinations": the audience comes from a lazy generator, is written in chunks, and PHP memory stays
 * flat. Every fifth item repeats an earlier destination: duplicates never become rows.
 */
function campaignAudience(int $total): Generator
{
    for ($i = 0; $i < $total; $i++) {
        $n = $i % 5 === 4 ? $i - 3 : $i;          // a duplicate of an earlier item
        yield $n % 2 === 0 ? H::device($n, 1, (string) ($n % 977)) : H::token($n);
    }
}

function uniqueAudience(int $total): int
{
    return $total - intdiv($total, 5);
}

function recordAudience(int $total): void
{
    $h = DatabaseLedgerWorld::harness(settings: new LedgerSettings(chunkSize: 1000, retry: new RetryPolicy));
    $h->ledger->recordIntent(H::intent(H::alert(), 'warmup'), [H::token('warm')]);

    gc_collect_cycles();
    $before = memory_get_usage();
    memory_reset_peak_usage();

    $result = $h->ledger->recordIntent(H::intent(H::alert(), 'black-friday', campaign: 'bf'), campaignAudience($total));

    $grown = memory_get_peak_usage() - $before;
    $counts = $h->ledger->campaignCounts('default', 'bf');

    expect($result->added)->toBe(uniqueAudience($total))
        ->and($result->existing)->toBe($total - uniqueAudience($total))
        ->and($counts->total())->toBe(uniqueAudience($total))
        ->and($counts->of(DeliveryState::Pending))->toBe(uniqueAudience($total))
        ->and($grown)->toBeLessThan(24 * 1024 * 1024, 'memory grew by '.$grown.' bytes');
}

it('records 50,000 destinations from a generator with flat memory and no duplicate rows', function (): void {
    recordAudience(50_000);
});

it('records a million destinations from a generator with flat memory and no duplicate rows', function (): void {
    recordAudience(1_000_000);
})->group('slow');
