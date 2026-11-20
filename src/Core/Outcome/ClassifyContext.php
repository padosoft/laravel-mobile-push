<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Outcome;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;

final readonly class ClassifyContext
{
    public function __construct(
        public Destination $destination,
        public BindingRef $binding,
        /** The request was an FCM validate_only call: a 2xx means Validated, not Accepted. */
        public bool $validateOnly = false,
    ) {}
}
