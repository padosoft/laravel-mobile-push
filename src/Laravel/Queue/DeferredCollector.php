<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Padosoft\MobilePush\Core\Driver\AttemptReport;
use Padosoft\MobilePush\Core\Driver\GuardTransition;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Driver\SendObserver;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Retry\RetryBudget;

/**
 * @internal per-call observer of SendPushJob: keeps the Deferred destinations with the budget to continue.
 */
final class DeferredCollector implements SendObserver
{
    /** @var list<array{0: QueuedDestination, 1: \DateTimeImmutable}> */
    public array $deferred = [];

    public function attemptFinished(AttemptReport $attempt): void {}

    public function resultProduced(SendResult $result, RetryBudget $budget, SendItem $item): void
    {
        if ($result->status === SendStatus::Deferred && $result->nextAttemptAt !== null) {
            $this->deferred[] = [QueuedDestination::of($item->destination, $budget), $result->nextAttemptAt];
        }
    }

    public function tokenRefreshed(BindingRef $binding, int $credentialGeneration, string $credentialIdentity): void {}

    public function guardTransition(GuardTransition $transition): void {}

    public function providerThrottled(BindingRef $binding, string $accountKey, \DateTimeImmutable $until): void {}
}
