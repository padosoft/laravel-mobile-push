<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Driver;

use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;

/** What a DestinationGate decided for one destination: skip it for good, or defer it to a later instant. */
final readonly class GateDenial
{
    private function __construct(
        public SendStatus $status,
        public Reason $reason,
        public ?\DateTimeImmutable $retryAt,
    ) {}

    /** Not sent, no further attempt (e.g. PolicyIneligible, PolicyFrequencyCapped). */
    public static function skip(Reason|ReasonCode $reason, ?string $hint = null): self
    {
        return new self(SendStatus::Skipped, self::reason($reason, $hint), null);
    }

    /** Not sent now; a later attempt at `$retryAt` may be made (e.g. a quiet-hours window). */
    public static function defer(Reason|ReasonCode $reason, \DateTimeImmutable $retryAt, ?string $hint = null): self
    {
        return new self(SendStatus::Deferred, self::reason($reason, $hint), $retryAt);
    }

    private static function reason(Reason|ReasonCode $reason, ?string $hint): Reason
    {
        if ($reason instanceof ReasonCode) {
            return Reason::of($reason, null, $hint);
        }

        return $hint === null ? $reason : $reason->withHint($hint);
    }
}
