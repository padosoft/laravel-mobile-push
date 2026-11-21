<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Guard;

/**
 * The guard state could not be updated because other writers kept winning the compare-and-set.
 *
 * Nothing was granted and nothing was sent: the sender turns it into Deferred results, never into a thrown send.
 *
 * @internal
 */
final class GuardStateContended extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Guard state is too contended to update.');
    }
}
