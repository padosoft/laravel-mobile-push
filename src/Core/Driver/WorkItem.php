<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Driver;

use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Message\CompiledMessage;
use Padosoft\MobilePush\Core\Outcome\Decision;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Retry\RetryBudget;
use Padosoft\MobilePush\Core\Transport\Request;

/**
 * Mutable per-destination state of one send call.
 *
 * @internal
 */
final class WorkItem
{
    public ?Reason $lastCause = null;

    public ?Decision $lastDecision = null;

    public ?CompiledMessage $compiled = null;

    public ?Request $request = null;

    public ?AuthToken $token = null;

    public ?string $receiptMarker = null;

    public bool $markerResolved = false;

    public bool $messageIdOnWire = false;

    public bool $sent = false;

    public bool $failoverUsed = false;

    public int $generation = 0;

    public string $poolKey = '';

    public function __construct(
        public readonly int $position,
        public readonly SendItem $item,
        public RetryBudget $budget,
        public readonly string $messageId,
        public readonly ?\DateTimeImmutable $expiresAt,
    ) {}

    public function destination(): Destination
    {
        return $this->item->destination;
    }
}
