<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Ledger;

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Ledger\LedgerSettings;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Laravel\Ledger\ClaimStrategy;
use Padosoft\MobilePush\Laravel\Ledger\DatabaseLedger;
use Padosoft\MobilePush\Laravel\Ledger\LedgerModule;
use Padosoft\MobilePush\Tests\Scenario\Ledger\LedgerHarness;
use Padosoft\MobilePush\Tests\Support\FakeClock;
use Psr\Clock\ClockInterface;

/** Wires clock, runtime keys and the two ledger tables into a Testbench app. */
final class DatabaseLedgerWorld
{
    public static function migrate(?string $connection = null): void
    {
        config()->set('mobile-push.ledger.connection', $connection);
        foreach (LedgerModule::migrationPaths() as $path) {
            (require $path)->up();
        }
    }

    public static function harness(
        ?string $connection = null,
        ClaimStrategy $strategy = ClaimStrategy::Auto,
        ?FakeClock $clock = null,
        ?LedgerSettings $settings = null,
        bool $migrate = true,
    ): LedgerHarness {
        $clock ??= new FakeClock;
        $settings ??= LedgerHarness::settings();
        [$index, $cipher] = self::keys();
        app()->instance(ClockInterface::class, $clock);
        if ($migrate) {
            self::migrate($connection);
        }

        $ledger = new DatabaseLedger(app('db'), $index, $cipher, $clock, $settings, $connection, strategy: $strategy);
        $rows = static fn (): array => [
            'effects' => DB::connection($connection)->table('mobile_push_effects')->get()->map(static fn (object $r): array => (array) $r)->values()->all(),
            'deliveries' => DB::connection($connection)->table('mobile_push_deliveries')->get()->map(static fn (object $r): array => (array) $r)->values()->all(),
        ];

        return new LedgerHarness($ledger, $clock, $settings, $rows, 'database');
    }

    /** @return array{0: TokenIndex, 1: TokenCipher} */
    public static function keys(): array
    {
        static $keys = null;
        $keys ??= LedgerHarness::privacy();
        app()->instance(TokenIndex::class, $keys[0]);
        app()->instance(TokenCipher::class, $keys[1]);

        return $keys;
    }
}
