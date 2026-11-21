<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Notifications\Fixtures;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Notifications\CustomizesPendingPush;
use Padosoft\MobilePush\Laravel\Notifications\HasPushConnection;
use Padosoft\MobilePush\Laravel\Notifications\HasPushFallback;
use Padosoft\MobilePush\Laravel\Notifications\HasPushScope;
use Padosoft\MobilePush\Laravel\Sending\PendingSend;

/** "Your order shipped": push first, optionally an email when the push is known not to have reached the person. */
class OrderShipped extends Notification implements CustomizesPendingPush, HasPushConnection, HasPushFallback, HasPushScope
{
    /**
     * @param  list<string>  $channels
     * @param  list<string>  $fallback
     */
    public function __construct(
        public array $channels = ['push'],
        public array $fallback = [],
        public ?string $scope = null,
        public ?string $app = null,
        public bool $bulk = false,
    ) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return $this->channels;
    }

    public function toPush(mixed $notifiable): PushMessage
    {
        return PushMessage::make('Order shipped', 'Arrives Friday')->data(['order' => '42']);
    }

    public function toApns(mixed $notifiable): PushMessage
    {
        return PushMessage::make('Order shipped (iOS)', 'Arrives Friday');
    }

    public function toFcm(mixed $notifiable): PushMessage
    {
        return PushMessage::make('Order shipped (Android)', 'Arrives Friday');
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your order shipped')->line('It arrives on Friday.');
    }

    /** @return list<string> */
    public function pushFallback(mixed $notifiable): array
    {
        return $this->fallback;
    }

    public function pushScope(mixed $notifiable): ?string
    {
        return $this->scope;
    }

    public function pushConnection(mixed $notifiable): ?string
    {
        return $this->app;
    }

    public function pushOptions(PendingSend $pending, mixed $notifiable): PendingSend
    {
        return $this->bulk ? $pending->bulk() : $pending;
    }
}
