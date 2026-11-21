<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

/**
 * Optional, cheap "is anything left to do?" checks: an indexed existence test on the open states (pending,
 * deferred, leased, sending), whatever the number of terminal rows. Use it before counting: counts() and
 * campaignCounts() read every row of the effect or campaign.
 */
interface SettlementProbe
{
    /** Whether the effect has a delivery that is not final (waiting or in flight). False for an unknown effect. */
    public function hasOpenDeliveries(string $scope, string $effectId): bool;

    /** Whether the campaign has a delivery that is not final, in any of its effects. */
    public function campaignHasOpenDeliveries(string $scope, string $campaignId): bool;
}
