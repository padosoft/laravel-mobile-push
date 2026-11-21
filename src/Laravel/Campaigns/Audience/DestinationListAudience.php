<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Audience;

use Padosoft\MobilePush\Core\Exceptions\ValidationException;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Ledger\LedgerRules;
use Padosoft\MobilePush\Core\Ledger\RecordedDestination;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;

/**
 * An explicit list of typed destinations (tokens, topics, conditions, broadcast channels, registry devices), kept
 * with the campaign (the parameters are stored encrypted). For more than MAX entries use DestinationFileAudience.
 *
 * Order and bound: the list order; the upper bound is its length. Duplicates are allowed in the input: the ledger
 * keeps one delivery per logical destination, so a token listed three times is sent once. A destination that
 * carries a registry reference keeps it, so the relay re-checks that device in the registry before sending.
 */
final readonly class DestinationListAudience implements Audience
{
    public const CURSOR = 'i';

    public const MAX = 50_000;

    /**
     * @param  list<array{k: string, v: string|null, r: array{0: string, 1: int, 2: string|null, 3: string|null}|null}>  $items
     */
    private function __construct(private array $items) {}

    /** @param  iterable<Destination>  $destinations */
    public static function of(iterable $destinations): self
    {
        $items = [];
        foreach ($destinations as $destination) {
            if (count($items) >= self::MAX) {
                throw new \InvalidArgumentException('A list audience holds at most '.self::MAX.' destinations: use DestinationFileAudience for imports.');
            }
            $ref = $destination->ref();
            $items[] = [
                'k' => $destination->kind()->value,
                // A registry reference is enough: the registry holds the token (RecordedDestination has no value).
                'v' => $destination instanceof RecordedDestination ? null : $destination->value(),
                'r' => $ref === null ? null : [$ref->id, $ref->generation, $ref->ownerType, $ref->ownerId],
            ];
        }

        return new self($items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function toArray(): array
    {
        return ['items' => $this->items];
    }

    public static function fromArray(array $parameters): static
    {
        $items = [];
        $stored = $parameters['items'] ?? [];
        foreach (is_array($stored) ? $stored : [] as $item) {
            if (! is_array($item) || ! is_string($item['k'] ?? null)) {
                continue;
            }
            $value = $item['v'] ?? null;
            $ref = $item['r'] ?? null;
            $items[] = [
                'k' => $item['k'],
                'v' => is_string($value) ? $value : null,
                'r' => is_array($ref) && is_scalar($ref[0] ?? null)
                    ? [(string) $ref[0], is_int($ref[1] ?? null) ? $ref[1] : 1, is_string($ref[2] ?? null) ? $ref[2] : null, is_scalar($ref[3] ?? null) ? (string) $ref[3] : null]
                    : null,
            ];
        }

        return new self($items);
    }

    public function upperBound(AudienceContext $context): string
    {
        return KeysetCursor::encode(self::CURSOR, count($this->items));
    }

    public function slice(AudienceContext $context, ?string $cursor, string $upperBound, int $limit): AudienceSlice
    {
        $from = KeysetCursor::decode(self::CURSOR, $cursor);
        $upper = min(KeysetCursor::decode(self::CURSOR, $upperBound), count($this->items));
        $to = min($upper, $from + $limit);

        $destinations = [];
        $invalid = 0;
        for ($i = $from; $i < $to; $i++) {
            $destination = self::destination($this->items[$i]);
            if ($destination === null) {
                $invalid++;

                continue;
            }
            $destinations[] = $destination;
        }

        return new AudienceSlice($destinations, KeysetCursor::encode(self::CURSOR, max($from, $to)), $to >= $upper, $invalid);
    }

    public function reach(): AudienceReach
    {
        $individual = $fanOut = false;
        foreach ($this->items as $item) {
            $kind = DestinationKind::tryFrom($item['k']);
            if ($kind?->isIndividual() === true) {
                $individual = true;
            } else {
                $fanOut = true;
            }
        }

        return match (true) {
            $fanOut && $individual => AudienceReach::Mixed,
            $fanOut => AudienceReach::FanOut,
            default => AudienceReach::Individual,
        };
    }

    /** @param  array{k: string, v: string|null, r: array{0: string, 1: int, 2: string|null, 3: string|null}|null}  $item */
    private static function destination(array $item): ?Destination
    {
        $kind = DestinationKind::tryFrom($item['k']);
        if ($kind === null) {
            return null;
        }
        $ref = $item['r'] === null ? null : new DestinationRef($item['r'][0], $item['r'][1], $item['r'][2], $item['r'][3]);

        if ($item['v'] === null) {
            return $ref === null ? null : new RecordedDestination($kind, LedgerRules::deviceKey($kind->value, $ref->id), $ref);
        }

        try {
            $destination = PushDevice::build($kind, $item['v']);

            return $ref === null ? $destination : $destination->withRef($ref);
        } catch (ValidationException|\InvalidArgumentException) {
            return null;
        }
    }
}
