<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy\Badge;

/** The guard store did not accept a compare-and-set within the bounded number of attempts (or threw). */
final class BadgeStoreUnavailable extends \RuntimeException {}
