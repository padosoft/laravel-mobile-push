<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

use Padosoft\MobilePush\Core\Exceptions\IdempotencyConflictException;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Message\Message;

/**
 * Effects: the durable intent (outbox) and everything done to an effect as a whole.
 * Every method is scoped: an id from another scope is treated as not found.
 */
interface EffectStore
{
    /**
     * Record the intent: the effect and one delivery per unique logical destination. No network, no dispatch.
     *
     * The database implementation writes with plain inserts on the ledger connection, inside whatever transaction
     * the caller has open there, so the intent commits or rolls back with the business data. Destinations are
     * consumed lazily and written in chunks: pass a generator for large audiences.
     *
     * Same scope + binding identity + effect key within the dedupe window:
     *  - same fingerprint: idempotent, the existing effect is returned; destinations not yet present are added,
     *    existing rows (including cancelled tombstones) are untouched;
     *  - different fingerprint: IdempotencyConflictException, nothing is written.
     *
     * @param  iterable<Destination>  $destinations
     *
     * @throws IdempotencyConflictException
     */
    public function recordIntent(EffectIntent $intent, iterable $destinations): IntentResult;

    public function effect(string $scope, string $effectId): ?Effect;

    /** The decrypted message snapshot, or null when the effect is unknown or its snapshot was erased. */
    public function message(string $scope, string $effectId): ?Message;

    /** Cancel the non-terminal deliveries of an effect. Returns the rows changed. */
    public function cancelEffect(string $scope, string $effectId): int;

    /** Cancel the non-terminal deliveries of every effect of a campaign. Returns the rows changed. */
    public function cancelCampaign(string $scope, string $campaignId): int;

    /** Cancel the non-terminal deliveries addressed to an owner (registry owner or notifiable). */
    public function cancelOwner(string $scope, string $ownerType, string $ownerId): int;

    /**
     * Cancel the owner's non-terminal deliveries and erase their personal data: encrypted destination, owner and
     * device snapshot, and the message snapshot and notifiable of the effects that originate from them. Counters,
     * states and the non-reversible logical keys stay (the tombstones keep a re-submitted job from re-creating rows).
     *
     * Returns the delivery rows erased.
     */
    public function forgetOwner(string $scope, string $ownerType, string $ownerId): int;

    /**
     * Delete terminal deliveries last changed before now - retention whose effect is past its dedupe window, in
     * chunks, then the effects left without deliveries. Null scope = every scope (maintenance).
     */
    public function prune(int $retentionSeconds, ?string $scope = null): PruneResult;
}
