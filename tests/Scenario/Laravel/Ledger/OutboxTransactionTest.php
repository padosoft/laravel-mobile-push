<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Padosoft\MobilePush\Core\Exceptions\IdempotencyConflictException;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Ledger\DatabaseLedgerWorld;
use Padosoft\MobilePush\Tests\Scenario\Ledger\LedgerHarness as H;

/*
 * "Order committed, push committed": the intent is written on the ledger connection inside the caller's
 * transaction, so it lives and dies with the business data.
 */
beforeEach(function (): void {
    $this->h = DatabaseLedgerWorld::harness();
    Schema::create('orders_fixture', static function (Blueprint $table): void {
        $table->id();
        $table->string('status');
    });
});

it('order committed, push committed: the intent written inside the transaction is claimable', function (): void {
    DB::transaction(function (): void {
        DB::table('orders_fixture')->insert(['status' => 'paid']);
        $this->h->ledger->recordIntent(H::intent(H::alert()), [H::token(1), H::token(2)]);
    });

    expect(DB::table('orders_fixture')->count())->toBe(1)
        ->and($this->h->claimIds('default', 'worker', 10))->toHaveCount(2);
});

it('order rolled back, push rolled back: nothing exists, nothing is claimable', function (): void {
    DB::beginTransaction();
    DB::table('orders_fixture')->insert(['status' => 'paid']);
    $this->h->ledger->recordIntent(H::intent(H::alert()), [H::token(1), H::token(2)]);
    DB::rollBack();

    expect(DB::table('orders_fixture')->count())->toBe(0)
        ->and(DB::table('mobile_push_effects')->count())->toBe(0)
        ->and(DB::table('mobile_push_deliveries')->count())->toBe(0)
        ->and($this->h->ledger->claimDue('default', 'worker', 10))->toBe([]);
});

it('an exception after the intent inside the business transaction leaves no trace of the push', function (): void {
    expect(fn () => DB::transaction(function (): void {
        DB::table('orders_fixture')->insert(['status' => 'paid']);
        $this->h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)]);

        throw new RuntimeException('payment provider said no');
    }))->toThrow(RuntimeException::class);

    expect(DB::table('mobile_push_effects')->count())->toBe(0)
        ->and(DB::table('mobile_push_deliveries')->count())->toBe(0);
});

it('a conflicting replay inside the caller transaction writes nothing and leaves the transaction usable', function (): void {
    $this->h->ledger->recordIntent(H::intent(H::alert()), [H::token(1)]);

    DB::transaction(function (): void {
        DB::table('orders_fixture')->insert(['status' => 'paid']);
        try {
            $this->h->ledger->recordIntent(H::intent(H::alert('changed')), [H::token(2)]);
        } catch (IdempotencyConflictException) {
            // the caller decides; its own work goes on
        }
    });

    expect(DB::table('orders_fixture')->count())->toBe(1)
        ->and(DB::table('mobile_push_deliveries')->count())->toBe(1);
});
