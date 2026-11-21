<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Audience;

use Illuminate\Database\Eloquent\Builder;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Ledger\LedgerRules;
use Padosoft\MobilePush\Core\Ledger\RecordedDestination;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignException;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;

/**
 * The active devices of the device registry registered on the campaign's binding (scope + app identity), with
 * optional declarative filters: platform, token kind, a named Eloquent scope of the device model with JSON
 * parameters, and an id range.
 *
 * Order and bound: keyset pagination on the primary key (indexed, monotonic for auto-increment ids). The upper bound
 * is the highest id of the binding when the campaign starts, so a device registered later is never included; a
 * device re-registered later keeps its id and is planned with its current generation if its slice comes after.
 *
 * It reads only the key, kind, generation and owner columns: no token is decrypted or copied. Each destination is a
 * registry reference (RecordedDestination with its DestinationRef): the ledger stores the device id + generation and
 * the relay reads the token from the registry right before the network, checking state, generation and owner again.
 */
final readonly class DeviceAudience implements Audience
{
    public const CURSOR = 'id';

    /**
     * @param  list<scalar|null>  $scopeParameters
     */
    public function __construct(
        public ?Platform $platform = null,
        public ?DestinationKind $kind = null,
        /** A named local scope of the device model (e.g. "optedInTo" for scopeOptedInTo). */
        public ?string $scope = null,
        public array $scopeParameters = [],
        public ?int $fromId = null,
        public ?int $toId = null,
    ) {
        if ($scope !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $scope) !== 1) {
            throw new \InvalidArgumentException('A device scope must be a method name.');
        }
        if (($fromId !== null && $fromId < 1) || ($toId !== null && $toId < 1) || ($fromId !== null && $toId !== null && $fromId > $toId)) {
            throw new \InvalidArgumentException('The id range must be positive and ordered.');
        }
    }

    public static function all(): self
    {
        return new self;
    }

    public function onPlatform(Platform $platform): self
    {
        return new self($platform, $this->kind, $this->scope, $this->scopeParameters, $this->fromId, $this->toId);
    }

    public function ofKind(DestinationKind $kind): self
    {
        return new self($this->platform, $kind, $this->scope, $this->scopeParameters, $this->fromId, $this->toId);
    }

    /** Apply a named local scope of the device model, e.g. ->where('optedInTo', 'marketing'). */
    public function where(string $scope, int|float|string|bool|null ...$parameters): self
    {
        return new self($this->platform, $this->kind, $scope, array_values($parameters), $this->fromId, $this->toId);
    }

    /** Only device ids in [from, to] (inclusive; null = open). */
    public function idsBetween(?int $from, ?int $to): self
    {
        return new self($this->platform, $this->kind, $this->scope, $this->scopeParameters, $from, $to);
    }

    public function toArray(): array
    {
        return [
            'platform' => $this->platform?->value,
            'kind' => $this->kind?->value,
            'scope' => $this->scope,
            'scope_parameters' => $this->scopeParameters,
            'from_id' => $this->fromId,
            'to_id' => $this->toId,
        ];
    }

    public static function fromArray(array $parameters): static
    {
        $platform = $parameters['platform'] ?? null;
        $kind = $parameters['kind'] ?? null;
        $scope = $parameters['scope'] ?? null;
        $values = $parameters['scope_parameters'] ?? [];
        $from = $parameters['from_id'] ?? null;
        $to = $parameters['to_id'] ?? null;

        $list = [];
        foreach (is_array($values) ? $values : [] as $value) {
            $list[] = is_scalar($value) ? $value : null;
        }

        return new self(
            is_string($platform) ? Platform::from($platform) : null,
            is_string($kind) ? DestinationKind::from($kind) : null,
            is_string($scope) ? $scope : null,
            $list,
            is_int($from) ? $from : null,
            is_int($to) ? $to : null,
        );
    }

    public function upperBound(AudienceContext $context): string
    {
        $model = self::model();
        $max = $model->newQuery()->forBinding($context->binding)->max($model->getKeyName());
        $max = is_numeric($max) ? (int) $max : 0;
        if ($this->toId !== null) {
            $max = min($max, $this->toId);
        }

        return KeysetCursor::encode(self::CURSOR, $max);
    }

    public function slice(AudienceContext $context, ?string $cursor, string $upperBound, int $limit): AudienceSlice
    {
        $after = max(KeysetCursor::decode(self::CURSOR, $cursor), ($this->fromId ?? 1) - 1);
        $upper = KeysetCursor::decode(self::CURSOR, $upperBound);
        if ($after >= $upper) {
            return new AudienceSlice([], $cursor, true);
        }

        $model = self::model();
        $key = $model->getKeyName();
        $query = $model->newQuery()
            ->forBinding($context->binding)
            ->active()
            ->where($key, '>', $after)
            ->where($key, '<=', $upper);
        if ($this->platform !== null) {
            $query->where('platform', $this->platform->value);
        }
        if ($this->kind !== null) {
            $query->where('kind', $this->kind->value);
        }
        if ($this->scope !== null) {
            $query = $this->applyScope($query);
        }

        $rows = $query->orderBy($key)->limit($limit)
            ->toBase()
            ->get([$key, 'kind', 'generation', 'owner_type', 'owner_id']);

        $destinations = [];
        $last = $after;
        $invalid = 0;
        foreach ($rows as $row) {
            $row = (array) $row;
            $id = $row[$key] ?? null;
            if (! is_numeric($id)) {
                throw new CampaignException('The device audience needs an integer primary key.');
            }
            $last = (int) $id;
            $kind = is_string($row['kind'] ?? null) ? DestinationKind::tryFrom($row['kind']) : null;
            if ($kind === null) {
                $invalid++;

                continue;
            }
            $generation = is_numeric($row['generation'] ?? null) ? (int) $row['generation'] : 1;
            $ownerType = is_string($row['owner_type'] ?? null) ? $row['owner_type'] : null;
            $ownerId = is_scalar($row['owner_id'] ?? null) ? (string) $row['owner_id'] : null;
            $ref = new DestinationRef((string) $last, $generation, $ownerType, $ownerId);
            $destinations[] = new RecordedDestination($kind, LedgerRules::deviceKey($kind->value, (string) $last), $ref);
        }

        $exhausted = count($rows) < $limit || $last >= $upper;

        return new AudienceSlice($destinations, KeysetCursor::encode(self::CURSOR, $exhausted ? $upper : $last), $exhausted, $invalid);
    }

    public function reach(): AudienceReach
    {
        return $this->kind === null || $this->kind->isIndividual() ? AudienceReach::Individual : AudienceReach::FanOut;
    }

    private static function model(): PushDevice
    {
        return new (PushDevice::modelClass());
    }

    /**
     * @param  Builder<PushDevice>  $query
     * @return Builder<PushDevice>
     */
    private function applyScope(Builder $query): Builder
    {
        $model = $query->getModel();
        if ($this->scope === null || ! $model->hasNamedScope($this->scope)) {
            throw new CampaignException('The device model has no scope named "'.$this->scope.'".');
        }

        $scoped = $model->callNamedScope($this->scope, [$query, ...$this->scopeParameters]);

        return $scoped instanceof Builder ? $scoped : $query;
    }
}
