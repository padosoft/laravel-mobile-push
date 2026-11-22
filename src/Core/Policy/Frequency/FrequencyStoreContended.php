<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy\Frequency;

/**
 * The guard store is alive, but the counter's compare-and-set kept losing to other writers for the bounded number of
 * attempts. Not a store failure: the cap could not be checked, so nothing is allowed on this attempt. Internal.
 */
final class FrequencyStoreContended extends \RuntimeException {}
