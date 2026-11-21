<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Exceptions\IdempotencyConflictException;
use Padosoft\MobilePush\Core\Ledger\PayloadFingerprint;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Ledger\DatabaseLedgerWorld;
use Padosoft\MobilePush\Tests\Scenario\Ledger\LedgerHarness as H;

/*
 * "A one-time code in the ledger": the stored fingerprint of the message is keyed, so a database dump does not let
 * anyone confirm a guessed OTP, while a replay of the same effect key with the same message still deduplicates.
 */
beforeEach(function (): void {
    $this->h = DatabaseLedgerWorld::harness();
});

it('stores a keyed fingerprint that is not the plain hash of the message, and still deduplicates a replay', function (): void {
    $otp = PushMessage::make('Your code', '481516');

    $first = $this->h->ledger->recordIntent(H::intent($otp, 'otp-1'), [H::token(1)]);
    $again = $this->h->ledger->recordIntent(H::intent(PushMessage::make('Your code', '481516'), 'otp-1'), [H::token(1)]);
    $stored = (string) DB::table('mobile_push_effects')->value('fingerprint');

    expect($stored)->toMatch('/^v2:k\d+:[0-9a-f]{64}$/')
        ->and($stored)->not->toContain(substr(PayloadFingerprint::of($otp), 3))
        ->and($again->effect->id)->toBe($first->effect->id)
        ->and(DB::table('mobile_push_effects')->count())->toBe(1)
        ->and(fn () => $this->h->ledger->recordIntent(H::intent(PushMessage::make('Your code', '000000'), 'otp-1'), [H::token(1)]))
        ->toThrow(IdempotencyConflictException::class);
});

it('accepts a replay against a row written before fingerprints were keyed (v1)', function (): void {
    $message = H::alert();
    $first = $this->h->ledger->recordIntent(H::intent($message, 'legacy-1'), [H::token(1)]);
    DB::table('mobile_push_effects')->update(['fingerprint' => PayloadFingerprint::of($message)]);

    $again = $this->h->ledger->recordIntent(H::intent(H::alert(), 'legacy-1'), [H::token(1)]);

    expect($again->effect->id)->toBe($first->effect->id)
        ->and(fn () => $this->h->ledger->recordIntent(H::intent(H::alert('other'), 'legacy-1'), [H::token(1)]))
        ->toThrow(IdempotencyConflictException::class);
});
