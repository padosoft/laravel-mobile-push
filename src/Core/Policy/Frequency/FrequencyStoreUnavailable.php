<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy\Frequency;

/** The guard store did not accept a compare-and-set within the bounded number of attempts (or threw). Internal. */
final class FrequencyStoreUnavailable extends \RuntimeException {}
