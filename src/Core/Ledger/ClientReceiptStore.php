<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

/**
 * Client-reported facts about deliveries (receipts module). Implemented by both ledgers next to Ledger.
 *
 * Three facts are kept apart and never merged: provider accepted (the delivery state), client reported received and
 * client reported opened. A missing report means "not observed", never "not delivered". Every method is scoped: an
 * id from another scope is treated as not found.
 */
interface ClientReceiptStore
{
    /**
     * The delivery whose last recorded attempt used this sender-generated message id (the id the marker carries).
     * With several matches (an effect key reused after its dedupe window) the newest delivery wins.
     */
    public function deliveryByMessageId(string $scope, string $messageId): ?Delivery;

    /**
     * Record one client-reported event for a delivery, first write wins per (delivery, event). Only deliveries in
     * state Accepted or OutcomeUnknown take it (a report is evidence the push reached the phone, so an unknown
     * outcome keeps its provider outcome but gains the client event). `$at` is the event time, already clamped by the
     * caller (see Receipts\ClientTimestamp). Out of order is fine: `opened` may precede `received`.
     */
    public function recordClientEvent(string $scope, string $deliveryId, ClientEvent $event, \DateTimeImmutable $at): ClientEventRecording;

    public function effectFunnel(string $scope, string $effectId): ReceiptFunnel;

    public function campaignFunnel(string $scope, string $campaignId): ReceiptFunnel;
}
