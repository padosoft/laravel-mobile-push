<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

use Illuminate\Contracts\Container\Container;
use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Laravel\Contracts\QueuedSender;
use Padosoft\MobilePush\Laravel\Contracts\QueueOptions;

/**
 * The QueuedSender of the container: the light or the durable implementation, chosen by mobile-push.delivery.profile.
 * The single owner of queueing push work: everything else (facade, notification channel, campaigns) calls this.
 */
final readonly class ProfiledQueuedSender implements QueuedSender
{
    public function __construct(
        private Container $container,
        private QueueSettings $settings,
    ) {}

    public function queue(BindingRef $binding, Message $message, iterable $destinations, SendOptions $options, QueueOptions $queue): string
    {
        return $this->delegate()->queue($binding, $message, $destinations, $options, $queue);
    }

    public function profile(): string
    {
        return $this->settings->profile;
    }

    private function delegate(): QueuedSender
    {
        return $this->container->make($this->settings->durable() ? DurableQueuedSender::class : LightQueuedSender::class);
    }
}
