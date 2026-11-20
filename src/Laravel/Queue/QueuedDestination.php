<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Ledger\DestinationFactory;
use Padosoft\MobilePush\Core\Retry\RetryBudget;

/**
 * One destination as it travels inside an (encrypted) light job: the explicit kind, the raw value, the registry
 * reference when there is one, and the retry budget when the job is a continuation. The kind is stored, never
 * inferred from the value. Dumps never show the value.
 *
 * @phpstan-type Payload array{k: string, v: string, r?: array{0: string, 1: int, 2: string|null, 3: string|null}, b?: array<string, mixed>}
 */
final readonly class QueuedDestination
{
    /** Fixed per-destination overhead of the serialized array form, used by the byte estimate. */
    private const OVERHEAD_BYTES = 48;

    public function __construct(
        public Destination $destination,
        public ?RetryBudget $budget = null,
    ) {}

    public static function of(Destination $destination, ?RetryBudget $budget = null): self
    {
        return new self($destination, $budget);
    }

    /** @return Payload */
    public function toArray(): array
    {
        $out = ['k' => $this->destination->kind()->value, 'v' => $this->destination->value()];
        $ref = $this->destination->ref();
        if ($ref !== null) {
            $out['r'] = [$ref->id, $ref->generation, $ref->ownerType, $ref->ownerId];
        }
        if ($this->budget !== null) {
            $out['b'] = $this->budget->toArray();
        }

        return $out;
    }

    /** @param  Payload  $data */
    public static function fromArray(array $data): self
    {
        $ref = isset($data['r']) ? new DestinationRef($data['r'][0], $data['r'][1], $data['r'][2], $data['r'][3]) : null;

        return new self(
            DestinationFactory::make(DestinationKind::from($data['k']), $data['v'], $ref),
            isset($data['b']) ? RetryBudget::fromArray($data['b']) : null,
        );
    }

    public function toSendItem(): SendItem
    {
        return new SendItem($this->destination, $this->budget);
    }

    /** In-process deduplication key (kind + value; registry generation included when present). */
    public function dedupeKey(): string
    {
        $ref = $this->destination->ref();

        return hash('xxh128', $this->destination->kind()->value."\0".$this->destination->value()."\0".($ref === null ? '' : $ref->id.':'.$ref->generation), true);
    }

    /** Estimated serialized bytes of this entry inside a job, before encryption. */
    public function estimatedBytes(): int
    {
        $bytes = self::OVERHEAD_BYTES + strlen($this->destination->kind()->value) + strlen($this->destination->value());
        $ref = $this->destination->ref();
        if ($ref !== null) {
            $bytes += 40 + strlen($ref->id) + strlen((string) $ref->ownerType) + strlen((string) $ref->ownerId);
        }
        if ($this->budget !== null) {
            $bytes += 260;
        }

        return $bytes;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['destination' => '[redacted '.$this->destination->kind()->value.']', 'budget' => $this->budget === null ? 'none' : 'attempts '.$this->budget->attempts];
    }
}
