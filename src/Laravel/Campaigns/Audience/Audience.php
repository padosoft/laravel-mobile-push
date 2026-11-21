<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Audience;

/**
 * Who a campaign is for: a serializable description (never a closure) that yields destinations lazily, in a stable
 * order, from a resumable cursor.
 *
 * Semantics every implementation follows: the audience is bounded by an upper bound fixed ONCE, when the campaign
 * starts planning (upperBound()); slices never go past it, so what is added to the source after the start (a device
 * registered later, a line appended to the file) is not part of the campaign. Inside the bound the order is stable
 * (keyset pagination on an indexed, monotonic key), so a slice re-read from the same cursor yields the same
 * destinations, minus those that left the source meanwhile.
 *
 * An audience never decides consent or eligibility: the relay's gates do, immediately before the network.
 *
 * Host applications register their own class with AudienceRegistry::register() (or the
 * mobile-push.campaigns.audiences config map) and implement this interface; parameters must be plain JSON data.
 */
interface Audience
{
    /**
     * Plain data (scalars, lists and maps of scalars) that fromArray() turns back into an equal audience. Stored
     * encrypted with the campaign, so it may contain destination values.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /** @param  array<string, mixed>  $parameters  what toArray() returned */
    public static function fromArray(array $parameters): static;

    /** Fix the upper bound for this campaign (opaque to the planner, stored as is). */
    public function upperBound(AudienceContext $context): string;

    /**
     * Up to `$limit` destinations after `$cursor` (null = from the start), never past `$upperBound`.
     * Memory is bounded by `$limit`.
     */
    public function slice(AudienceContext $context, ?string $cursor, string $upperBound, int $limit): AudienceSlice;

    /** Whether its destinations are installations (per-recipient numbers are meaningful) or fan-out targets. */
    public function reach(): AudienceReach;
}
