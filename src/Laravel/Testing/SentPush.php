<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Laravel\Contracts\QueueOptions;

/**
 * One push the application asked for, to one destination: what PushFake recorded.
 *
 * A send to three devices records three SentPush objects sharing the same message instance.
 */
final class SentPush
{
    public readonly string $scope;

    public readonly string $connection;

    /**
     * @param  BindingRef  $binding  the resolved connection identity (scope, name, provider, app id, environment)
     * @param  bool  $queued  true when the application queued the push instead of sending it now
     * @param  SentOptions  $options  validate-only, traffic class, effect key and expiry (the expiry is filled in
     *                                when the pipeline pins it, which happens right after the send is recorded)
     * @param  SendResult|null  $result  the outcome the fake answered with (null for queued pushes)
     * @param  QueueOptions|null  $queueOptions  where/when the push was queued (queued pushes only)
     */
    public function __construct(
        public readonly BindingRef $binding,
        public readonly Destination $destination,
        public readonly Message $message,
        public SentOptions $options,
        public readonly bool $queued = false,
        public ?SendResult $result = null,
        public readonly ?QueueOptions $queueOptions = null,
    ) {
        $this->scope = $binding->scope;
        $this->connection = $binding->name;
    }

    /** @return array<string, mixed> never contains the raw destination */
    public function __debugInfo(): array
    {
        return [
            'scope' => $this->scope,
            'connection' => $this->connection,
            'destination' => Describe::destination($this->destination),
            'message' => $this->message::class,
            'queued' => $this->queued,
            'status' => $this->result?->status->value,
        ];
    }
}
