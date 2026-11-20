<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Transition;

use Illuminate\Database\Eloquent\Model;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Outcome\SendResult;

/**
 * The writes a serialized transition needs, for one kind of row (an activity, or a broadcast channel).
 * Every method writes under compare-and-set on the row version and returns false when the row moved.
 *
 * @internal
 *
 * @template T of Model
 */
interface TransitionStore
{
    /** @return T|null */
    public function find(string $id): ?Model;

    /** @param  T  $row */
    public function snapshot(Model $row): RowSnapshot;

    /** @param  T  $row */
    public function destination(Model $row): ?Destination;

    /**
     * @param  T  $row
     * @return array{0: int, 1: T}
     */
    public function nextSeq(Model $row): array;

    /** @param  T  $row */
    public function storePending(Model $row, TransitionCommand $command, TransitionPayload $payload, int $dueAt): bool;

    /** @param  T  $row  updated in memory on success */
    public function claim(Model $row, TransitionCommand $command, TransitionPayload $payload, TransitionDecision $decision): bool;

    /**
     * Record what the pipeline answered for a claimed transition. `$before` is the row as it was before the claim
     * (used to undo the claim when nothing left the server); `$sentUpTo` is the latest Unix second any request of
     * this transition may carry.
     *
     * @param  T  $before
     */
    public function record(Model $before, TransitionCommand $command, TransitionPayload $payload, TransitionDecision $decision, SendResult $result, int $sentUpTo): void;
}
