<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Devices;

use Carbon\CarbonImmutable;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Outcome\Decision;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Psr\Clock\ClockInterface;

/**
 * Applies a FINAL outcome to the registry. It acts only on the generation that was attempted: a late answer
 * about generation 7 never touches generation 8, even when the raw token is identical.
 */
final readonly class DeviceOutcomeApplier
{
    public function __construct(private ClockInterface $clock) {}

    public function applyDecision(DestinationRef $ref, Decision $decision): DeviceChangeResult
    {
        return $this->apply($ref, $decision->status, $decision->destinationAction, $decision->invalidatedAtMs, $decision->reason);
    }

    public function applyResult(SendResult $result): DeviceChangeResult
    {
        $ref = $result->destination->ref();

        return $ref === null
            ? new DeviceChangeResult(DeviceChange::RowMissing)
            : $this->apply($ref, $result->status, $result->destinationAction, $result->invalidatedAtMs, $result->reason);
    }

    public function apply(
        DestinationRef $ref,
        SendStatus $status,
        DestinationAction $action,
        ?int $invalidatedAtMs = null,
        ?Reason $reason = null,
    ): DeviceChangeResult {
        if ($status === SendStatus::OutcomeUnknown) {
            return new DeviceChangeResult(DeviceChange::UnknownOutcome);
        }

        if ($action === DestinationAction::None) {
            return new DeviceChangeResult(DeviceChange::NoAction);
        }

        $model = new (PushDevice::modelClass());

        return $model->getConnection()->transaction(function () use ($model, $ref, $action, $invalidatedAtMs, $reason): DeviceChangeResult {
            $device = $model->newQuery()->whereKey($ref->id)->lockForUpdate()->first();

            if ($device === null) {
                return new DeviceChangeResult(DeviceChange::RowMissing);
            }

            if ($device->generation !== $ref->generation) {
                return new DeviceChangeResult(DeviceChange::GenerationChanged, $device);
            }

            if ($device->state === DeviceState::Invalidated) {
                return new DeviceChangeResult(DeviceChange::AlreadyInvalidated, $device);
            }

            $reasonText = $reason === null ? null : ($reason->rawReason ?? $reason->code->value);
            $now = CarbonImmutable::instance($this->clock->now())->setTimezone(date_default_timezone_get());

            if ($action === DestinationAction::Quarantine) {
                if ($device->state === DeviceState::Quarantined) {
                    return new DeviceChangeResult(DeviceChange::AlreadyQuarantined, $device);
                }

                $device->state = DeviceState::Quarantined;
                $change = DeviceChange::Quarantined;
            } else {
                if ($invalidatedAtMs !== null && $invalidatedAtMs <= PushDevice::msOf($device->registered_at)) {
                    return new DeviceChangeResult(DeviceChange::StaleTimestamp, $device);
                }

                $device->state = DeviceState::Invalidated;
                $change = DeviceChange::Invalidated;
            }

            $device->state_reason = $reasonText === null ? null : substr($reasonText, 0, 64);
            $device->state_changed_at = $now;
            $device->save();

            return new DeviceChangeResult($change, $device);
        });
    }

    /** Record a send on the attempted generation only. */
    public function markSent(DestinationRef $ref, ?\DateTimeInterface $at = null): bool
    {
        $model = new (PushDevice::modelClass());
        $when = CarbonImmutable::instance($at ?? $this->clock->now())->setTimezone(date_default_timezone_get());

        return $model->newQuery()
            ->whereKey($ref->id)
            ->where('generation', $ref->generation)
            ->update(['last_sent_at' => $model->fromDateTime($when)]) > 0;
    }
}
