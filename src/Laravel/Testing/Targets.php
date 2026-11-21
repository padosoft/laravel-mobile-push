<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Identity\Destination;

/**
 * Turns what a test passes ("the user", a typed destination, a list of those) into typed destinations, with the
 * same rules as MobilePush::to(): a raw string is refused because the platform is never guessed.
 *
 * @internal
 */
final class Targets
{
    /** @return list<Destination> */
    public static function destinations(mixed $target): array
    {
        if ($target instanceof Destination) {
            return [$target];
        }
        if ($target instanceof SendItem) {
            return [$target->destination];
        }
        if (is_string($target)) {
            throw new InvalidDestinationException('A raw token needs its platform: pass a typed destination such as ApnsDeviceToken::of() or FcmToken::of(). The platform is never guessed.');
        }
        if (is_object($target) && method_exists($target, 'routeNotificationForPush')) {
            $routed = $target->routeNotificationForPush(null);

            return self::destinations($routed ?? []);
        }
        if (is_object($target) && method_exists($target, 'pushDestinations')) {
            return self::destinations($target->pushDestinations());
        }
        if (is_iterable($target)) {
            $out = [];
            foreach ($target as $item) {
                array_push($out, ...self::destinations($item));
            }

            return $out;
        }
        if ($target === null) {
            return [];
        }

        throw new InvalidDestinationException(sprintf('Cannot read push destinations from a %s.', get_debug_type($target)));
    }

    /** @param  list<Destination>  $haystack */
    public static function contains(array $haystack, Destination $needle): bool
    {
        foreach ($haystack as $candidate) {
            if ($candidate->kind() === $needle->kind() && $candidate->equals($needle)) {
                return true;
            }
        }

        return false;
    }
}
