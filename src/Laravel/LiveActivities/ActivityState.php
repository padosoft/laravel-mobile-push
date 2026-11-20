<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

/**
 * The server's view of one Live Activity. It is only what the server did and what APNs answered: what the device
 * actually shows is a separate fact (PushActivity::$client_reported_state), reported by the app.
 */
enum ActivityState: string
{
    /** A remote start was sent to a push-to-start token; the app has not reported the activity's update token yet. */
    case Requested = 'requested';
    /** The update token is known: updates and the end can be sent. */
    case Active = 'active';
    /** An end was requested (sent, coalesced or awaiting a retry). Terminal for updates: nothing but the end goes out. */
    case Ending = 'ending';
    /** Closed: the end was accepted by APNs, the token was invalidated, or the start was not accepted. */
    case Ended = 'ended';
    /** The local expiry passed: the server stopped sending. Says nothing about the device. */
    case Expired = 'expired';

    public function isClosed(): bool
    {
        return $this === self::Ended || $this === self::Expired;
    }

    /** @return list<string> */
    public static function open(): array
    {
        return [self::Requested->value, self::Active->value, self::Ending->value];
    }
}
