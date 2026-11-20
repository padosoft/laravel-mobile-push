<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Receipts;

use Illuminate\Contracts\Events\Dispatcher;
use Padosoft\MobilePush\Core\Ledger\ClientEvent;
use Padosoft\MobilePush\Core\Ledger\ClientEventRecording;
use Padosoft\MobilePush\Core\Ledger\ClientReceiptStore;
use Padosoft\MobilePush\Core\Ledger\Delivery;
use Padosoft\MobilePush\Core\Ledger\DeliveryStore;
use Padosoft\MobilePush\Core\Receipts\ClientTimestamp;
use Padosoft\MobilePush\Core\Receipts\DeliveryReference;
use Padosoft\MobilePush\Core\Receipts\ReceiptMarker;
use Padosoft\MobilePush\Laravel\Receipts\Events\PushOpenedReported;
use Padosoft\MobilePush\Laravel\Receipts\Events\PushReceivedReported;
use Psr\Clock\ClockInterface;

/**
 * Verify a marker, find its delivery in the marker's own scope, record the event once, announce it once.
 * Every failure is the same `Rejected`: malformed, forged, expired, unknown delivery, ineligible delivery.
 */
final class ReceiptRecorder
{
    public function __construct(
        private readonly ReceiptMarker $marker,
        private readonly ClientReceiptStore $store,
        private readonly ClockInterface $clock,
        private readonly Dispatcher $events,
        private readonly DeliveryStore $ledger,
    ) {}

    /**
     * Two ways to the row. (1) Primary: the ledger assigned the delivery its message id when it inserted the row and
     * the relay sent it on every attempt, so the lookup by stored `message_id` finds the row even when its outcome
     * was never recorded (response lost, worker died). (2) Fallback, only for markers issued before the ledger
     * assigned ids: the message id derived from the delivery ULID (see DeliveryReference).
     */
    private function find(string $scope, string $reference): ?Delivery
    {
        $delivery = $this->store->deliveryByMessageId($scope, $reference);
        if ($delivery !== null) {
            return $delivery;
        }

        $id = DeliveryReference::deliveryIdFor($reference);

        return $id === null ? null : $this->ledger->delivery($scope, $id);
    }

    public function record(ReceiptReport $report): ReceiptOutcome
    {
        $now = $this->clock->now();
        $verified = $this->marker->verify($report->marker, $now);
        if ($verified === null) {
            return ReceiptOutcome::Rejected;
        }

        // The scope comes from the authenticated marker, never from the request: tenant A's marker can only ever
        // look inside tenant A.
        $delivery = $this->find($verified->scope, $verified->deliveryReference);
        if ($delivery === null) {
            return ReceiptOutcome::Rejected;
        }

        $at = ClientTimestamp::clamp($report->at, $now, $delivery->createdAt);
        $recording = $this->store->recordClientEvent($delivery->scope, $delivery->id, $report->event, $at);

        if ($recording === ClientEventRecording::Recorded) {
            $this->events->dispatch($report->event === ClientEvent::Received
                ? new PushReceivedReported($delivery->scope, $delivery->effectId, $delivery->id, $delivery->kind, $now, $at)
                : new PushOpenedReported($delivery->scope, $delivery->effectId, $delivery->id, $delivery->kind, $now, $at));

            return ReceiptOutcome::Recorded;
        }

        return $recording === ClientEventRecording::Duplicate ? ReceiptOutcome::Duplicate : ReceiptOutcome::Rejected;
    }
}
