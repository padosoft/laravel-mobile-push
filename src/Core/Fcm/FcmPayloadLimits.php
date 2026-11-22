<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm;

use Padosoft\MobilePush\Core\Identity\DestinationKind;

/**
 * FCM payload limits and the counting rule this package applies.
 *
 * Limits: 4096 bytes for most messages, 2048 bytes for topic and condition messages.
 *
 * COUNTING RULE (pinned by tests/Fixtures/fcm/payload-size-rule.json). The size of a message is the sum, over
 * the following maps of the wire-form message, of strlen(key) + strlen(value) in UTF-8 bytes for every entry
 * whose value is a string:
 *   - `notification`                    (title, body, image)
 *   - `data`
 *   - `android.data` and `android_v2.data`
 *   - `android.notification` and `android_v2.remote_notification.notification`, only the keys
 *     `title`, `body` and `image` (the text-bearing fields)
 * No JSON punctuation, no target, no REST envelope (`message`, `validate_only`), no other field is counted.
 * The receipt marker (`mp_r`) and `deep_link` are data entries and therefore counted.
 *
 * What Google documents (ErrorCode reference, "Message too big", and "Set message type"; read on 2026-11-22): "the
 * total size of the payload data included in a message" may not exceed 4096 bytes for most messages or 2048 bytes
 * for messages to topics, "this includes both the keys and the values", and 4096 bytes is the maximum payload "for
 * both message types" (notification and data). The numbers and the counting of keys plus values are therefore
 * Google's. What is the package's own, conservative reading, not measured against FCM: which fields besides `data`
 * count (it counts the notification texts too) and the 2048 limit applied to conditions as well as topics.
 */
final class FcmPayloadLimits
{
    public const DEFAULT_BYTES = 4096;

    public const TOPIC_BYTES = 2048;

    public static function limitFor(DestinationKind $kind): int
    {
        return match ($kind) {
            DestinationKind::FcmTopic, DestinationKind::FcmCondition => self::TOPIC_BYTES,
            default => self::DEFAULT_BYTES,
        };
    }

    /**
     * @param  array<array-key, mixed>  $message  wire-form message object (snake_case), as built by the compiler
     */
    public static function measure(array $message): int
    {
        $bytes = self::mapBytes($message['notification'] ?? null)
            + self::mapBytes($message['data'] ?? null);

        $android = $message['android'] ?? null;
        if (is_array($android)) {
            $bytes += self::mapBytes($android['data'] ?? null);
            $bytes += self::mapBytes($android['notification'] ?? null, ['title', 'body', 'image']);
        }

        $v2 = $message['android_v2'] ?? null;
        if (is_array($v2)) {
            $bytes += self::mapBytes($v2['data'] ?? null);
            $remote = $v2['remote_notification'] ?? null;
            if (is_array($remote)) {
                $bytes += self::mapBytes($remote['notification'] ?? null, ['title', 'body', 'image']);
            }
        }

        return $bytes;
    }

    /**
     * @param  list<string>|null  $only
     */
    private static function mapBytes(mixed $map, ?array $only = null): int
    {
        if (! is_array($map)) {
            return 0;
        }

        $bytes = 0;
        foreach ($map as $key => $value) {
            $key = (string) $key;
            if (! is_string($value) || ($only !== null && ! in_array($key, $only, true))) {
                continue;
            }
            $bytes += strlen($key) + strlen($value);
        }

        return $bytes;
    }
}
