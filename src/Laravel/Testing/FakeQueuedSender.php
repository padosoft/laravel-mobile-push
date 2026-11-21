<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

use Illuminate\Support\Str;
use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Laravel\Contracts\QueuedSender;
use Padosoft\MobilePush\Laravel\Contracts\QueueOptions;

/** Records queue requests instead of dispatching jobs. @internal */
final readonly class FakeQueuedSender implements QueuedSender
{
    public function __construct(private PushFake $fake) {}

    public function queue(BindingRef $binding, Message $message, iterable $destinations, SendOptions $options, QueueOptions $queue): string
    {
        $effectId = $options->effectKey ?? (string) Str::ulid();
        $sent = new SentOptions($options->validateOnly, $options->trafficClass, $options->effectKey, $options->expiresAt);
        foreach ($destinations as $destination) {
            $this->fake->record(new SentPush($binding, $destination, $message, $sent, true, null, $queue));
        }

        return $effectId;
    }
}
