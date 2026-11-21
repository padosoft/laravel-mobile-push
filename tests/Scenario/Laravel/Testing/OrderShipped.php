<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Testing;

use Illuminate\Notifications\Notification;
use Padosoft\MobilePush\Core\Message\PushMessage;

/** The host application's own notification class. */
final class OrderShipped extends Notification
{
    public function __construct(public readonly int $orderId = 42) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['push'];
    }

    public function toPush(object $notifiable): PushMessage
    {
        return PushMessage::make('Order shipped', 'Order #'.$this->orderId.' is on its way')->data(['order' => (string) $this->orderId]);
    }
}
