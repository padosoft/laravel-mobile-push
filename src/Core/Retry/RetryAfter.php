<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Retry;

/**
 * Parser of the HTTP Retry-After header (RFC 9110 §10.2.3): delta-seconds or an HTTP-date.
 */
final class RetryAfter
{
    /** Values above this are clamped: a provider never legitimately asks to wait more than a day. */
    public const MAX_SECONDS = 86_400;

    /**
     * @param  \DateTimeImmutable  $reference  the instant the response was produced (its Date header when available)
     * @return int|null seconds to wait (>= 0), or null when absent or unparseable
     */
    public static function seconds(?string $value, \DateTimeImmutable $reference): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            return strlen($value) > 6 ? self::MAX_SECONDS : min(self::MAX_SECONDS, (int) $value);
        }

        $date = self::httpDate($value);
        if ($date === null) {
            return null;
        }

        return max(0, min(self::MAX_SECONDS, $date->getTimestamp() - $reference->getTimestamp()));
    }

    /**
     * IMF-fixdate, plus the two obsolete forms recipients must accept (RFC 850, asctime).
     */
    public static function httpDate(string $value): ?\DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        foreach (['D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, trim((string) preg_replace('/\s+/', ' ', $value)), $utc);
            if ($date !== false) {
                $errors = \DateTimeImmutable::getLastErrors();
                if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                    return $date;
                }
            }
        }

        return null;
    }
}
