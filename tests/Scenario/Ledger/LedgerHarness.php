<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Ledger;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Ledger\ClaimedDelivery;
use Padosoft\MobilePush\Core\Ledger\EffectIntent;
use Padosoft\MobilePush\Core\Ledger\InMemoryLedger;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Core\Ledger\LedgerSettings;
use Padosoft\MobilePush\Core\Ledger\Recording;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Tests\Support\FakeClock;

/**
 * One ledger implementation plus everything a scenario needs to drive it by hand (the worker is simulated:
 * claim -> mark started -> build a SendResult -> record). Used by the shared contract in LedgerContract.php.
 */
final class LedgerHarness
{
    /**
     * @param  \Closure(): array{effects: list<array<string, mixed>>, deliveries: list<array<string, mixed>>}  $rows
     */
    public function __construct(
        public readonly Ledger $ledger,
        public readonly FakeClock $clock,
        public readonly LedgerSettings $settings,
        private readonly \Closure $rows,
        public readonly string $name,
    ) {}

    public static function inMemory(?FakeClock $clock = null, ?LedgerSettings $settings = null): self
    {
        $clock ??= new FakeClock;
        $settings ??= self::settings();
        [$index, $cipher] = self::privacy();
        $ledger = new InMemoryLedger($index, $cipher, $clock, $settings);

        return new self($ledger, $clock, $settings, static fn (): array => $ledger->dumpRows(), 'memory');
    }

    public static function settings(): LedgerSettings
    {
        return new LedgerSettings(leaseSeconds: 60, dedupeSeconds: 3600, chunkSize: 100, retry: new RetryPolicy(maxAttempts: 5, maxElapsedSeconds: 3600));
    }

    /** @return array{0: TokenIndex, 1: TokenCipher} runtime-generated keys */
    public static function privacy(): array
    {
        return [new TokenIndex(new KeyRing([1 => random_bytes(32)])), new TokenCipher(new KeyRing([1 => random_bytes(32)]))];
    }

    /**
     * Test hook: rewrite the stored logical keys of an effect's deliveries (to stage rows in an older key format).
     *
     * @param  \Closure(string): string  $rewrite
     */
    public function rewriteLogicalKeys(string $effectId, \Closure $rewrite): void
    {
        if ($this->ledger instanceof \Padosoft\MobilePush\Laravel\Ledger\DatabaseLedger) {
            $table = $this->ledger->connection()->table('mobile_push_deliveries');
            foreach ((clone $table)->where('effect_id', $effectId)->get(['id', 'logical_key']) as $row) {
                (clone $table)->where('id', $row->id)->update(['logical_key' => $rewrite((string) $row->logical_key)]);
            }

            return;
        }
        $deliveries = new \ReflectionProperty($this->ledger, 'deliveries');
        $keys = new \ReflectionProperty($this->ledger, 'deliveryKeys');
        $rows = $deliveries->getValue($this->ledger);
        $index = $keys->getValue($this->ledger);
        foreach ($rows as $id => $row) {
            if ($row['effect_id'] === $effectId) {
                unset($index[$effectId.'|'.$row['logical_key']]);
                $rows[$id]['logical_key'] = $rewrite((string) $row['logical_key']);
                $index[$effectId.'|'.$rows[$id]['logical_key']] = $id;
            }
        }
        $deliveries->setValue($this->ledger, $rows);
        $keys->setValue($this->ledger, $index);
    }

    /** @return array{effects: list<array<string, mixed>>, deliveries: list<array<string, mixed>>} */
    public function rows(): array
    {
        return ($this->rows)();
    }

    // ------------------------------------------------------------ fixtures

    public static function binding(string $scope = 'default'): BindingRef
    {
        return new BindingRef($scope, 'main', Provider::Apns, 'com.example.app', Environment::Production);
    }

    public static function token(int|string $n): ApnsDeviceToken
    {
        return ApnsDeviceToken::of(str_pad(dechex(is_int($n) ? $n : crc32($n)), 8, '0', STR_PAD_LEFT).str_repeat('ab', 28));
    }

    public static function fcm(string $seed): FcmToken
    {
        return FcmToken::of('fcm-'.$seed.'-'.str_repeat('Zq9', 20));
    }

    public static function device(int $id, int $generation = 1, string $ownerId = '1'): Destination
    {
        return self::token($id)->withRef(new DestinationRef((string) $id, $generation, 'App\\Models\\User', $ownerId));
    }

    public static function topic(string $name): FcmTopic
    {
        return FcmTopic::of($name);
    }

    public static function alert(string $body = 'Your order shipped'): PushMessage
    {
        return PushMessage::make('Order', $body);
    }

    public static function liveUpdate(string $body = 'Score 1-0'): PushMessage
    {
        return PushMessage::make('Match', $body)->replaceable();
    }

    public static function intent(PushMessage $message, ?string $key = 'order-42', string $scope = 'default', ?string $campaign = null, ?\DateTimeImmutable $expiresAt = null): EffectIntent
    {
        return new EffectIntent(self::binding($scope), $message, $key, expiresAt: $expiresAt, campaignId: $campaign);
    }

    /**
     * @return \Generator<int, Destination>
     */
    public static function tokens(int $count, int $from = 0): \Generator
    {
        for ($i = $from; $i < $from + $count; $i++) {
            yield self::token($i);
        }
    }

    // ------------------------------------------------------------ simulated worker

    public function budgetAfterOneAttempt(): RetryBudget
    {
        return RetryBudget::start($this->settings->retry, $this->clock->now())->withAttempt();
    }

    public function accepted(ClaimedDelivery $claimed, string $apnsId = 'apns-id-1'): SendResult
    {
        return new SendResult(
            $claimed->destination ?? $claimed->delivery->toSendResult()->destination,
            SendStatus::Accepted,
            Reason::none(),
            1,
            $apnsId,
            200,
            deliveryId: $claimed->id(),
        );
    }

    public function deferred(ClaimedDelivery $claimed, int $inSeconds): SendResult
    {
        return new SendResult(
            $claimed->destination ?? $claimed->delivery->toSendResult()->destination,
            SendStatus::Deferred,
            Reason::of(ReasonCode::ProviderUnavailable, 'ServiceUnavailable'),
            1,
            httpStatus: 503,
            nextAttemptAt: $this->clock->now()->modify("+{$inSeconds} seconds"),
            deliveryId: $claimed->id(),
        );
    }

    /** Claim, mark started, send (pretend), record Accepted. */
    public function acceptAll(string $scope, string $owner, int $limit = 1000, ?string $effectId = null): int
    {
        $n = 0;
        foreach ($this->ledger->claimDue($scope, $owner, $limit, $effectId) as $claimed) {
            if ($this->ledger->markAttemptStarted($claimed->lease)
                && $this->ledger->record($claimed->lease, $this->accepted($claimed), $this->budgetAfterOneAttempt()) === Recording::Recorded) {
                $n++;
            }
        }

        return $n;
    }

    /** @return list<string> */
    public function claimIds(string $scope, string $owner, int $limit, ?string $effectId = null): array
    {
        return array_map(static fn (ClaimedDelivery $c): string => $c->id(), $this->ledger->claimDue($scope, $owner, $limit, $effectId));
    }

    public function expireLeases(): void
    {
        $this->clock->advance($this->settings->leaseSeconds + 1);
    }
}
