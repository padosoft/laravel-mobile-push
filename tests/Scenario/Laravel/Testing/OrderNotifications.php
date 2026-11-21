<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Testing;

use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendReport;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

/** The host application's own code under test: sends order notifications and reacts to the report. */
final class OrderNotifications
{
    /** @var list<int> */
    public array $uninstalled = [];

    public int $unknown = 0;

    public function shipped(Shopper $shopper, int $itemsInCart = 1): ?SendReport
    {
        if ($itemsInCart === 0) {
            return null; // nothing to tell
        }

        $report = MobilePush::to($shopper)->send(PushMessage::make('Order shipped', 'It arrives on Friday')->data(['order' => '42']));
        foreach ($report->results() as $result) {
            if ($result->status === SendStatus::Rejected && $result->reason->code->value === 'destination_unregistered') {
                $this->uninstalled[] = $shopper->id;
            }
            if ($result->status === SendStatus::OutcomeUnknown) {
                $this->unknown++;
            }
        }

        return $report;
    }

    public function shippedLater(Shopper $shopper): string
    {
        return MobilePush::to($shopper)->onQueue('push')->queue(PushMessage::make('Order shipped'));
    }
}
