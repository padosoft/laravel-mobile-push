<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Devices;

use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;

/** Result of the pre-network check for one registry destination. */
final readonly class Eligibility
{
    private function __construct(public bool $eligible, public ?Ineligibility $cause = null) {}

    public static function yes(): self
    {
        return new self(true);
    }

    public static function no(Ineligibility $cause): self
    {
        return new self(false, $cause);
    }

    /** The Reason to put on a Skipped result: null when eligible. */
    public function reason(): ?Reason
    {
        return $this->cause === null
            ? null
            : Reason::of(ReasonCode::PolicyIneligible, $this->cause->value, $this->cause->hint());
    }
}
