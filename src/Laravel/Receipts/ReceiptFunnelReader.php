<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Receipts;

use Padosoft\MobilePush\Core\Ledger\ClientReceiptStore;
use Padosoft\MobilePush\Core\Ledger\ReceiptFunnel;

/**
 * The funnel per effect and per campaign, always with its denominator and coverage. Read it as "reported by the
 * app": a missing report is not observed, never "not delivered".
 */
final class ReceiptFunnelReader
{
    public function __construct(private readonly ClientReceiptStore $store) {}

    public function forEffect(string $scope, string $effectId): ReceiptFunnel
    {
        return $this->store->effectFunnel($scope, $effectId);
    }

    public function forCampaign(string $scope, string $campaignId): ReceiptFunnel
    {
        return $this->store->campaignFunnel($scope, $campaignId);
    }
}
