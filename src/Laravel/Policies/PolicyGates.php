<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Policies;

use Padosoft\MobilePush\Core\Driver\DestinationGate;

/**
 * The enabled sending-policy gates, ready for SendOptions::$gates. Resolve it from the container
 * (`app(PolicyGates::class)->gates()`) when building the dispatcher's default options; a disabled policy
 * contributes nothing. Run these after the eligibility and consent gates: a destination that is not eligible must
 * never consume a unit of the cap.
 */
final readonly class PolicyGates
{
    public function __construct(private ?DestinationGate $frequency = null) {}

    /** @return list<DestinationGate> */
    public function gates(): array
    {
        return $this->frequency === null ? [] : [$this->frequency];
    }
}
