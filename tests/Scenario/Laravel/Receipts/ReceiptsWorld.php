<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Receipts;

use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Ledger\ClientReceiptStore;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Receipts\DeliveryReference;
use Padosoft\MobilePush\Laravel\Ledger\DatabaseLedger;
use Padosoft\MobilePush\Laravel\Ledger\LedgerModule;
use Padosoft\MobilePush\Laravel\Receipts\ReceiptMarkerIssuer;
use Padosoft\MobilePush\Laravel\Receipts\ReceiptsModule;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Ledger\DatabaseLedgerWorld;
use Padosoft\MobilePush\Tests\Scenario\Ledger\LedgerHarness as H;
use Padosoft\MobilePush\Tests\Support\FakeClock;
use Psr\Clock\ClockInterface;

/** A Testbench app with the durable ledger, the receipts module on, a fake clock and a worker that "sends". */
final class ReceiptsWorld
{
    public int $sequence = 0;

    private function __construct(
        public readonly FakeClock $clock,
        public readonly DatabaseLedger $ledger,
        public readonly H $harness,
    ) {}

    /** @param array<string, mixed> $config extra mobile-push config, e.g. ['receipts.rate_limit.per_ip' => 3] */
    public static function boot(array $config = [], bool $enabled = true, bool $durable = true, bool $routes = true): self
    {
        config()->set('mobile-push.delivery.profile', $durable ? 'durable' : 'light');
        config()->set('mobile-push.receipts.enabled', $enabled);
        foreach ($config as $key => $value) {
            config()->set('mobile-push.receipts.'.$key, $value);
        }

        $clock = new FakeClock;
        app()->instance(ClockInterface::class, $clock);
        $harness = DatabaseLedgerWorld::harness(clock: $clock, settings: LedgerModule::settings(app()));
        app()->instance(DatabaseLedger::class, $harness->ledger);
        assert($harness->ledger instanceof DatabaseLedger);

        if ($routes) {
            ReceiptsModule::boot(app());
        }

        return new self($clock, $harness->ledger, $harness);
    }

    public function store(): ClientReceiptStore
    {
        return app(ClientReceiptStore::class);
    }

    public function issuer(): ReceiptMarkerIssuer
    {
        return app(ReceiptMarkerIssuer::class);
    }

    /**
     * Send one push the way the relay does: the issuer is asked for a marker with the sender's message id, the
     * provider answers, the worker records the result with that message id in its meta.
     *
     * @return array{deliveryId: string, marker: ?string, messageId: string, effectId: string}
     */
    public function send(Destination $destination, SendStatus $status = SendStatus::Accepted, string $scope = 'default', ?string $campaign = null, bool $deliveryIdAsMessageId = true): array
    {
        $n = ++$this->sequence;
        $effect = $this->ledger->recordIntent(H::intent(H::alert("body {$n}"), "key-{$n}", $scope, $campaign), [$destination])->effect;
        $claimed = $this->ledger->claimDue($scope, 'worker', 1, $effect->id)[0];
        $this->ledger->markAttemptStarted($claimed->lease);
        // A relay that follows the contract passes the delivery id as the message id; otherwise a random one.
        $messageId = ($deliveryIdAsMessageId ? DeliveryReference::messageIdFor($claimed->id()) : null) ?? sprintf('00000000-0000-4000-8000-%012d', $n);
        $marker = $this->issuer()->issue($scope, $destination, $messageId);

        if ($status === SendStatus::OutcomeUnknown) {
            // The request left, the response was lost, the worker died: the lease expires.
            $this->clock->advance(LedgerModule::settings(app())->leaseSeconds + 1);
            $this->ledger->reclaimExpiredLeases($scope);
        } else {
            $this->ledger->record($claimed->lease, new SendResult($destination, $status, Reason::none(), 1, 'prov-'.$n, 200, deliveryId: $claimed->id(), meta: ['message_id' => $messageId]), $this->harness->budgetAfterOneAttempt());
        }

        return ['deliveryId' => $claimed->id(), 'marker' => $marker, 'messageId' => $messageId, 'effectId' => $effect->id];
    }
}
