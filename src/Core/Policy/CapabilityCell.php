<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy;

/** One cell of the capability matrix: the support level plus an optional one-line note. */
final readonly class CapabilityCell
{
    public function __construct(
        public Support $support,
        public ?string $note = null,
    ) {}
}
