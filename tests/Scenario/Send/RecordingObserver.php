<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Send;

use Padosoft\MobilePush\Core\Driver\AttemptReport;
use Padosoft\MobilePush\Core\Driver\GuardTransition;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Driver\SendObserver;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Retry\RetryBudget;

/** Keeps everything the sender reported, for assertions. */
final class RecordingObserver implements SendObserver
{
    /** @var list<AttemptReport> */
    public array $attempts = [];

    /** @var list<array{result: SendResult, budget: RetryBudget, item: SendItem}> */
    public array $results = [];

    /** @var list<array{generation: int, identity: string}> */
    public array $refreshes = [];

    /** @var list<GuardTransition> */
    public array $transitions = [];

    /** @var list<\DateTimeImmutable> */
    public array $throttles = [];

    public function attemptFinished(AttemptReport $attempt): void
    {
        $this->attempts[] = $attempt;
    }

    public function resultProduced(SendResult $result, RetryBudget $budget, SendItem $item): void
    {
        $this->results[] = ['result' => $result, 'budget' => $budget, 'item' => $item];
    }

    public function tokenRefreshed(BindingRef $binding, int $credentialGeneration, string $credentialIdentity): void
    {
        $this->refreshes[] = ['generation' => $credentialGeneration, 'identity' => $credentialIdentity];
    }

    public function guardTransition(GuardTransition $transition): void
    {
        $this->transitions[] = $transition;
    }

    public function providerThrottled(BindingRef $binding, string $accountKey, \DateTimeImmutable $until): void
    {
        $this->throttles[] = $until;
    }
}
