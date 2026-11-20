<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Receipts;

use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Receipts\ReceiptMarker;
use Psr\Clock\ClockInterface;

/**
 * Issues the receipt marker for one destination of a send, or nothing.
 *
 * Plug it into the send options: `$options->withReceiptMarker($issuer->callback($scope))`. It returns null (no
 * marker, nothing breaks) when receipts are off, when the profile is not durable, for every destination that is not
 * one installation (topic, condition, broadcast channel: one payload reaches many phones, so no individual receipt
 * exists), for Live Activity tokens (their payloads never carry a marker), and for messages the caller excluded.
 */
final class ReceiptMarkerIssuer
{
    private const REMEMBER = 5000;

    /** @var array<string, true> sender message ids a marker was issued for in this process (bounded, oldest out) */
    private array $issued = [];

    /**
     * @param  \Closure(): bool  $active  receipts enabled AND durable profile; read at call time
     */
    public function __construct(
        private readonly ReceiptMarker $marker,
        private readonly ClockInterface $clock,
        private readonly \Closure $active,
        private readonly int $ttlSeconds = 604_800,
    ) {}

    /** Kinds that never get a marker even though they are one installation. */
    private static function carriesMarker(DestinationKind $kind): bool
    {
        return $kind->isIndividual()
            && $kind !== DestinationKind::ApnsLiveActivityToken
            && $kind !== DestinationKind::ApnsPushToStartToken;
    }

    public function issue(string $scope, Destination $destination, string $messageId): ?string
    {
        if (! ($this->active)() || ! self::carriesMarker($destination->kind())) {
            return null;
        }

        try {
            $marker = $this->marker->issue($scope, $messageId, $this->clock->now()->modify('+'.$this->ttlSeconds.' seconds'));
        } catch (\Throwable) {
            return null;   // never throw for one destination
        }

        $this->issued[$messageId] = true;
        if (count($this->issued) > self::REMEMBER) {
            array_shift($this->issued);
        }

        return $marker;
    }

    /**
     * The `receiptMarker` callback of SendOptions for one scope.
     *
     * @param  bool  $carriesMarker  pass false for a message that must not carry one (the callback then returns null)
     * @return \Closure(Destination, string): ?string
     */
    public function callback(string $scope, bool $carriesMarker = true): \Closure
    {
        return $carriesMarker
            ? fn (Destination $destination, string $messageId): ?string => $this->issue($scope, $destination, $messageId)
            : static fn (Destination $destination, string $messageId): ?string => null;
    }

    /** Whether a marker was issued for this sender message id by this process (feeds the ledger's coverage flag). */
    public function wasIssued(string $messageId): bool
    {
        return isset($this->issued[$messageId]);
    }
}
