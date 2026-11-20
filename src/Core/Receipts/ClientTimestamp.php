<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Receipts;

/** A phone's clock is not trusted: a reported time is forced into [the delivery's creation, the server's now]. */
final class ClientTimestamp
{
    /**
     * @param  \DateTimeImmutable|null  $reported  what the client said (null: it said nothing, the server time is used)
     * @param  \DateTimeImmutable  $serverNow  when the report reached the server
     * @param  \DateTimeImmutable  $floor  the earliest sane time (the delivery's creation time)
     */
    public static function clamp(?\DateTimeImmutable $reported, \DateTimeImmutable $serverNow, \DateTimeImmutable $floor): \DateTimeImmutable
    {
        if ($reported === null || $reported > $serverNow) {
            return $serverNow;
        }

        return $reported < $floor ? $floor : $reported;
    }

    /** Parse an ISO-8601 / RFC 3339 instant; null when it is not one (never throws). */
    public static function parse(string $value): ?\DateTimeImmutable
    {
        if (strlen($value) > 40 || preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:?\d{2})$/', $value) !== 1) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
