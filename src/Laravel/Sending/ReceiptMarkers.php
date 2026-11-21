<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending;

use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Identity\Destination;

/**
 * Per-call receipt-marker bookkeeping of the push manager.
 *
 *  - A marker is issued only for a destination that carries a ledger delivery id (a SendItem with `deliveryId`): a
 *    receipt is matched to a delivery row, so a marker for a send that has no row (the synchronous path, the light
 *    profile) would be a dead reference. The ledger relay is the path that passes delivery ids.
 *  - Every marker issued is remembered by the sender message id it was issued for, so the send log can be handed
 *    the marker as a runtime secret (`secrets` context key) for that result and never write it in a log line. The
 *    values live in an SplObjectStorage: var_export() and serialization of this object never show them.
 */
final class ReceiptMarkers
{
    /** @var \WeakMap<Destination, true> destinations that carry a ledger delivery id */
    private \WeakMap $withDelivery;

    /** @var \SplObjectStorage<object, array<string, string>> one entry: message id => marker */
    private \SplObjectStorage $issued;

    private object $slot;

    public function __construct()
    {
        $this->withDelivery = new \WeakMap;
        $this->issued = new \SplObjectStorage;
        $this->slot = new \stdClass;
        $this->issued[$this->slot] = [];
    }

    /**
     * Pass the destinations through, noting which carry a ledger delivery id. Lazy: a stream stays a stream.
     *
     * @param  iterable<Destination|SendItem>  $destinations
     * @return \Generator<int, Destination|SendItem>
     */
    public function watch(iterable $destinations): \Generator
    {
        foreach ($destinations as $value) {
            if ($value instanceof SendItem && $value->deliveryId !== null) {
                $this->withDelivery[$value->destination] = true;
            }
            yield $value;
        }
    }

    /**
     * @param  \Closure(Destination, string): ?string  $issuer
     * @return \Closure(Destination, string): ?string
     */
    public function wrap(\Closure $issuer): \Closure
    {
        return function (Destination $destination, string $messageId) use ($issuer): ?string {
            if (! isset($this->withDelivery[$destination])) {
                return null;
            }
            $marker = $issuer($destination, $messageId);
            if ($marker !== null && $marker !== '') {
                $map = $this->issued[$this->slot];
                $map[$messageId] = $marker;
                $this->issued[$this->slot] = $map;
            }

            return $marker;
        };
    }

    /**
     * The marker issued for a sender message id, forgotten once read (sensitive: for redaction only).
     *
     * @return list<string>
     */
    public function take(?string $messageId): array
    {
        if ($messageId === null) {
            return [];
        }
        $map = $this->issued[$this->slot];
        $marker = $map[$messageId] ?? null;
        if ($marker === null) {
            return [];
        }
        unset($map[$messageId]);
        $this->issued[$this->slot] = $map;

        return [$marker];
    }

    /** @return array<string, int> */
    public function __debugInfo(): array
    {
        return ['issued' => count($this->issued[$this->slot])];
    }

    /** @return array<mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('Receipt markers cannot be serialized.');
    }
}
