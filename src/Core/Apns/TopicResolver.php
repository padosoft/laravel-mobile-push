<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns;

use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;

/** Turns (bundle id, push type) into the `apns-topic` header value. */
final class TopicResolver
{
    /**
     * @param  string|null  $explicit  a topic chosen by the caller: always wins (required for `mdm`)
     *
     * @throws InvalidMessageException the topic would be empty/unsafe, or `mdm` has no explicit topic
     */
    public static function resolve(string $bundleId, PushType $type, ?string $explicit = null): string
    {
        if ($explicit !== null) {
            return self::checked($explicit, 'explicit topic');
        }

        if ($type->requiresExplicitTopic()) {
            throw new InvalidMessageException('The "mdm" push type needs an explicit topic: use the UID from the subject of the MDM push certificate.');
        }

        return self::checked($bundleId, 'bundle id').$type->topicSuffix();
    }

    private static function checked(string $value, string $what): string
    {
        if ($value === '' || preg_match('/^[\x21-\x7e]+$/', $value) !== 1) {
            throw new InvalidMessageException("The APNs {$what} must be a non-empty string of visible ASCII characters.");
        }

        return $value;
    }
}
