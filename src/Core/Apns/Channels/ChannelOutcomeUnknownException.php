<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns\Channels;

use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;

/**
 * `create()` may have created a channel whose id we never received (the response was lost, or the 201 had no
 * `apns-channel-id`). Retrying blindly could create another channel on every attempt and burn the 10,000-channel
 * limit, so nothing is retried automatically: call `ChannelClient::reconcile()` (which lists with `all()`) to find the orphan.
 */
final class ChannelOutcomeUnknownException extends ChannelApiException
{
    public static function forCreate(string $requestId, ?int $status = null): self
    {
        return new self(
            'The outcome of the channel creation is unknown: a channel may exist whose id was not received. '
            .'Do not retry blindly; reconcile through ChannelClient::all() / reconcile() to find it.',
            $status ?? 0,
            new Reason(ReasonCode::TransportUnknown, null, 'Reconcile with ChannelClient::reconcile() before creating again.'),
            $requestId,
        );
    }
}
