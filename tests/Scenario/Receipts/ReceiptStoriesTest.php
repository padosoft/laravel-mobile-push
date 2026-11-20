<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Ledger\ClientEvent;
use Padosoft\MobilePush\Core\Ledger\ClientEventRecording;
use Padosoft\MobilePush\Core\Ledger\ClientReceiptStore;
use Padosoft\MobilePush\Core\Ledger\DeliveryState;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Receipts\ClientEvidence;
use Padosoft\MobilePush\Core\Receipts\ClientTimestamp;
use Padosoft\MobilePush\Core\Receipts\DeliveryReference;
use Padosoft\MobilePush\Core\Receipts\ReceiptMarker;
use Padosoft\MobilePush\Tests\Scenario\Ledger\LedgerHarness as H;

/*
 * The receipts stories without the framework: a marker is issued while sending, the app reports through the ledger.
 * The HTTP layer has the same stories in tests/Scenario/Laravel/Receipts.
 */

/** What the endpoint does, in a few lines, on top of the in-memory ledger. */
function clientReports(H $h, ReceiptMarker $marker, string $token, ClientEvent $event, ?DateTimeImmutable $at = null): ?ClientEventRecording
{
    $verified = $marker->verify($token, $h->clock->now());
    $deliveryId = $verified === null ? null : DeliveryReference::deliveryIdFor($verified->deliveryReference);
    $delivery = $deliveryId === null ? null : $h->ledger->delivery($verified->scope, $deliveryId);
    if ($delivery === null) {
        return null;
    }
    assert($h->ledger instanceof ClientReceiptStore);

    return $h->ledger->recordClientEvent($delivery->scope, $delivery->id, $event, ClientTimestamp::clamp($at, $h->clock->now(), $delivery->createdAt));
}

it('markers issued under key version 1 still verify after version 2 becomes current; new ones use version 2', function (): void {
    $k1 = random_bytes(32);
    $k2 = random_bytes(32);
    $before = new ReceiptMarker(new KeyRing([1 => $k1]));
    $after = new ReceiptMarker(new KeyRing([1 => $k1, 2 => $k2], 2));
    $now = new DateTimeImmutable('2026-11-20T10:00:00Z');
    $expires = $now->modify('+7 days');
    $ref = '0191a2b3-c4d5-4e6f-8a7b-9c0d1e2f3a4b';

    $old = $before->issue('default', $ref, $expires);
    $new = $after->issue('default', $ref, $expires);

    expect($after->verify($old, $now)?->keyVersion)->toBe(1)
        ->and($after->verify($new, $now)?->keyVersion)->toBe(2)
        ->and(ReceiptMarker::versionOf($new))->toBe(2)
        ->and($before->verify($new, $now))->toBeNull()   // an old server does not know version 2
        // retiring version 1 is how a leaked key stops working
        ->and((new ReceiptMarker(new KeyRing([2 => $k2])))->verify($old, $now))->toBeNull();
});

it('a marker fits the budget: well under 100 bytes for the default scope', function (): void {
    $marker = new ReceiptMarker(new KeyRing([1 => random_bytes(32)]));
    $token = $marker->issue('default', DeliveryReference::messageIdFor('01JD0000000000000000000000') ?? '', new DateTimeImmutable('2026-11-27T10:00:00Z'));

    expect(strlen($token))->toBeLessThanOrEqual(55)->and(strlen($token))->toBeLessThan(100);
});

it('a story: accepted, reported, funnel with denominator; an unknown outcome confirmed by the phone', function (): void {
    $h = H::inMemory();
    $marker = new ReceiptMarker(new KeyRing([1 => random_bytes(32)]));
    $effect = $h->ledger->recordIntent(H::intent(H::alert()), [H::token(1), H::token(2), H::token(3)])->effect;
    $claims = $h->ledger->claimDue('default', 'w', 10);
    $markers = [];

    foreach ($claims as $i => $claimed) {
        $markerId = DeliveryReference::messageIdFor($claimed->id()) ?? '';
        $markers[$i] = $marker->issue('default', $markerId, $h->clock->now()->modify('+7 days'));
        $h->ledger->markAttemptStarted($claimed->lease);
        if ($i < 2) {
            $accepted = $h->accepted($claimed);
            $h->ledger->record($claimed->lease, new SendResult($accepted->destination, SendStatus::Accepted, $accepted->reason, 1, 'p', 200, deliveryId: $claimed->id(), meta: ['message_id' => $markerId, 'receipt_marker' => '1']), $h->budgetAfterOneAttempt());
        }
    }
    $h->expireLeases();
    $h->ledger->reclaimExpiredLeases('default');   // the third delivery: response lost
    $h->clock->advance(5);

    // Delivery 0 reports both; delivery 1 says nothing; delivery 2 (unknown) reports received.
    expect(clientReports($h, $marker, $markers[0], ClientEvent::Opened))->toBe(ClientEventRecording::Recorded)
        ->and(clientReports($h, $marker, $markers[0], ClientEvent::Received))->toBe(ClientEventRecording::Recorded)
        ->and(clientReports($h, $marker, $markers[0], ClientEvent::Received))->toBe(ClientEventRecording::Duplicate)
        ->and(clientReports($h, $marker, $markers[2], ClientEvent::Received))->toBe(ClientEventRecording::Recorded);

    assert($h->ledger instanceof ClientReceiptStore);
    $funnel = $h->ledger->effectFunnel('default', $effect->id);
    $unknown = $h->ledger->delivery('default', $claims[2]->id());

    expect($funnel->toArray())->toMatchArray([
        'accepted' => 2, 'accepted_with_marker' => 2, 'accepted_without_marker' => 0,
        'reported_received' => 1, 'reported_opened' => 1, 'received_not_observed' => 1,
        'unknown_outcome' => 1, 'unknown_client_confirmed' => 1, 'accepted_or_client_confirmed' => 3,
        'marker_coverage' => 1.0, 'received_rate' => 0.5,
    ])
        ->and($unknown->state)->toBe(DeliveryState::OutcomeUnknown)
        ->and(ClientEvidence::acceptedOrClientConfirmed($unknown))->toBeTrue();
});

it('a tenant A marker can never touch tenant B: the scope inside the marker is the only scope used', function (): void {
    $h = H::inMemory();
    $marker = new ReceiptMarker(new KeyRing([1 => random_bytes(32)]));
    $h->ledger->recordIntent(H::intent(H::alert(), 'k', 'tenant-b'), [H::token(1)]);
    $theirs = $h->ledger->claimDue('tenant-b', 'w', 1)[0];
    $h->ledger->markAttemptStarted($theirs->lease);
    $h->ledger->record($theirs->lease, $h->accepted($theirs), $h->budgetAfterOneAttempt());

    $forged = $marker->issue('tenant-a', DeliveryReference::messageIdFor($theirs->id()) ?? '', $h->clock->now()->modify('+1 day'));

    expect(clientReports($h, $marker, $forged, ClientEvent::Received))->toBeNull()
        ->and($h->ledger->delivery('tenant-b', $theirs->id())?->clientReceivedAt)->toBeNull();
});
