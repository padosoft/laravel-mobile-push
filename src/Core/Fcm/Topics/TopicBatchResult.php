<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Topics;

use Padosoft\MobilePush\Core\Outcome\Reason;

/** Per-installation outcome of subscribeMany()/unsubscribeMany(). Never carries the registration id. */
final readonly class TopicBatchResult
{
    private function __construct(
        public bool $ok,
        /** Subscribe: the subscription already existed. */
        public bool $alreadyInState,
        /** HTTP status; 0 when no response exists (transport failure, simulate, blocked). */
        public int $status,
        public Reason $reason,
    ) {}

    public static function done(int $status, bool $alreadyInState = false): self
    {
        return new self(true, $alreadyInState, $status, Reason::none());
    }

    public static function failed(int $status, Reason $reason): self
    {
        return new self(false, false, $status, $reason);
    }
}
