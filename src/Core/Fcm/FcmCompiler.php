<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm;

use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Exceptions\PayloadTooLargeException;
use Padosoft\MobilePush\Core\Exceptions\UnsupportedCapabilityException;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidNotification;
use Padosoft\MobilePush\Core\Fcm\Message\FcmFields;
use Padosoft\MobilePush\Core\Fcm\Message\FcmMessage;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Message\CompileContext;
use Padosoft\MobilePush\Core\Message\CompiledMessage;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\MessageCompiler;
use Padosoft\MobilePush\Core\Message\MessagePriority;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Message\Support\JsonEncoder;
use Padosoft\MobilePush\Core\Message\Support\TextFitter;

/**
 * Compiles a PushMessage or an FcmMessage into the body of
 * `POST https://fcm.googleapis.com/v1/projects/{project}/messages:send`.
 *
 * Decisions worth knowing:
 * - Wire names are the protobuf (snake_case) names, which the API accepts together with the camelCase names
 *   used by the Discovery document. The schema contract test maps one onto the other.
 * - `validate_only` is emitted only when true.
 * - Null values in `PushMessage::$data` are dropped with a warning; arrays/objects are rejected.
 * - Remaining TTL is `expiresAt - now`, rounded down to whole seconds, floored at 0 and capped at 4 weeks
 *   (the Discovery maximum), recomputed at every call.
 * - When the FCM overlay of a PushMessage uses `android_v2`, the unified fields are mapped into `android_v2`
 *   instead of `android` (the two are mutually exclusive). V2 has no `priority`: HIGH needs nothing, NORMAL is
 *   reported as a warning, `silent` becomes `background_sync`.
 * - Size rules: see FcmPayloadLimits.
 */
final class FcmCompiler implements MessageCompiler
{
    private const MAX_TTL_SECONDS = 2_419_200;

    private const MAX_CONDITION_TOPICS = 5;

    public function supports(Message $message): bool
    {
        return $message instanceof PushMessage || $message instanceof FcmMessage;
    }

    public function compile(Message $message, Destination $destination, CompileContext $context): CompiledMessage
    {
        $kind = $destination->kind();
        $targetField = match ($kind) {
            DestinationKind::FcmToken => 'token',
            DestinationKind::FcmFid => 'fid',
            DestinationKind::FcmTopic => 'topic',
            DestinationKind::FcmCondition => 'condition',
            default => throw new InvalidDestinationException("An FCM message cannot be sent to a {$kind->value} destination."),
        };
        $this->checkTarget($kind, $destination->value());

        $warnings = [];
        $truncate = false;

        if ($message instanceof PushMessage) {
            $useV2 = $message->fcmOverlay?->hasAndroidV2() === true;
            $tree = $this->fromPush($message, $useV2, $warnings);
            if ($message->fcmOverlay !== null) {
                $tree = self::merge($tree, $message->fcmOverlay->toArray());
            }
            $truncate = $message->truncate;
            $deepLink = $message->deepLink;
        } elseif ($message instanceof FcmMessage) {
            $tree = $message->toArray();
            $truncate = $message->truncatesText();
            $deepLink = null;
        } else {
            throw new InvalidMessageException('The FCM compiler supports PushMessage and FcmMessage, got '.get_debug_type($message).'.');
        }

        if (array_key_exists('android', $tree) && array_key_exists('android_v2', $tree)) {
            throw new InvalidMessageException('android and android_v2 are mutually exclusive: the message sets both.');
        }

        $this->checkDataKeys($tree);
        $this->applyExpiry($tree, $context, $warnings);

        if ($deepLink !== null) {
            $this->putData($tree, 'deep_link', $deepLink, 'deepLink');
        }
        if ($context->receiptMarker !== null) {
            $this->putData($tree, 'mp_r', $context->receiptMarker, 'receipt marker');
        }

        $limit = FcmPayloadLimits::limitFor($kind);
        $this->encode($targetField, $destination->value(), $tree, $context->validateOnly); // validates UTF-8 early

        $bytes = FcmPayloadLimits::measure($tree);
        if ($bytes > $limit) {
            if (! $truncate) {
                throw new PayloadTooLargeException("The FCM payload is {$bytes} bytes, over the {$limit}-byte limit for {$kind->value} messages. Shorten it or opt in to truncation (->truncate()), which only shortens title and body.");
            }
            $tree = $this->truncateText($tree, $limit, $bytes, $warnings);
            $bytes = FcmPayloadLimits::measure($tree);
        }

        return new CompiledMessage(
            headers: ['content-type' => 'application/json'],
            body: $this->encode($targetField, $destination->value(), $tree, $context->validateOnly),
            payloadBytes: $bytes,
            limitBytes: $limit,
            warnings: $warnings,
            meta: [
                'target_type' => $targetField,
                'validate_only' => $context->validateOnly ? 'true' : 'false',
            ],
        );
    }

    /**
     * @param  list<string>  $warnings
     * @return array<array-key, mixed>
     */
    private function fromPush(PushMessage $push, bool $useV2, array &$warnings): array
    {
        $this->rejectIosOnly($push, $warnings);

        $silent = $push->silent;
        if ($silent) {
            $conflicts = array_keys(array_filter([
                'title' => $push->title !== null,
                'body' => $push->body !== null,
                'image' => $push->image !== null,
                'sound' => $push->sound !== null,
                'badge' => $push->badge !== null,
                'actionCategory' => $push->actionCategory !== null,
                'group' => $push->group !== null,
            ]));
            if ($conflicts !== []) {
                $list = implode(', ', $conflicts);
                if (! $push->lenient) {
                    throw new InvalidMessageException("A silent message is data-only and cannot carry user-visible fields ({$list}). Remove them or drop ->silent().");
                }
                $warnings[] = "Silent message: dropped user-visible fields ({$list}).";
            }
        }

        $tree = [];

        if (! $silent) {
            $notification = array_filter(
                ['title' => $push->title, 'body' => $push->body, 'image' => $push->image],
                static fn (?string $v): bool => $v !== null,
            );
            $notification !== [] && $tree['notification'] = $notification;
        }

        $stringified = FcmFields::stringifyData($push->data, 'data');
        foreach ($stringified['dropped'] as $key) {
            $warnings[] = "Data key \"{$key}\" has a null value and was dropped.";
        }
        $stringified['data'] !== [] && $tree['data'] = $stringified['data'];

        $notification = new AndroidNotification;
        if (! $silent) {
            $push->badge !== null && $notification->notificationCount($push->badge);
            $push->sound !== null && $notification->sound($push->sound);
            $push->actionCategory !== null && $notification->clickAction($push->actionCategory);
            $push->group !== null && $notification->tag($push->group);
        }

        $high = ! $silent && $push->priority === MessagePriority::High;

        if ($useV2) {
            $v2 = [];
            $push->collapseKey !== null && $v2['collapse_key'] = $push->collapseKey;
            if (! $notification->isEmpty()) {
                $v2['remote_notification'] = ['notification' => $notification->toArray(true)];
            }
            $silent && $v2['background_sync'] = new \stdClass;
            if (! $silent && ! $high) {
                $warnings[] = 'android_v2 has no priority field: NORMAL priority was not applied.';
            }
            $tree['android_v2'] = $v2;
        } else {
            $android = [];
            $push->collapseKey !== null && $android['collapse_key'] = $push->collapseKey;
            $android['priority'] = $high ? 'HIGH' : 'NORMAL';
            ! $notification->isEmpty() && $android['notification'] = $notification->toArray(false);
            $tree['android'] = $android;
        }

        return $tree;
    }

    /** @param list<string> $warnings */
    private function rejectIosOnly(PushMessage $push, array &$warnings): void
    {
        $present = array_keys(array_filter([
            'subtitle' => $push->subtitle !== null,
            'critical' => $push->critical,
            'timeSensitive' => $push->timeSensitive,
            'mutableContent' => $push->mutableContent,
        ]));

        if ($present === []) {
            return;
        }

        $list = implode(', ', $present);
        if (! $push->lenient) {
            throw new UnsupportedCapabilityException("FCM/Android has no equivalent for: {$list}. Set the platform behaviour explicitly with ->fcm(fn (FcmMessage \$m) => \$m->apns(...)) for iOS devices reached through FCM, or call ->lenient() to drop it with a warning.");
        }

        foreach ($present as $capability) {
            $warnings[] = "Dropped iOS-only capability \"{$capability}\": FCM has no equivalent (lenient mode).";
        }
    }

    private function checkTarget(DestinationKind $kind, string $value): void
    {
        if ($kind === DestinationKind::FcmCondition) {
            $topics = preg_match_all('/\bin\s+topics\b/', $value);
            if ($topics > self::MAX_CONDITION_TOPICS) {
                throw new InvalidDestinationException('An FCM condition can combine at most '.self::MAX_CONDITION_TOPICS." topics, got {$topics}.");
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $tree
     */
    private function checkDataKeys(array $tree): void
    {
        foreach ($this->dataMaps($tree) as $label => $map) {
            foreach (array_keys($map) as $key) {
                if (FcmFields::isReservedDataKey((string) $key)) {
                    throw new InvalidMessageException("{$label} key \"{$key}\" is reserved by FCM (\"from\", \"message_type\", prefixes \"google.\" and \"gcm.\").");
                }
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $tree
     * @return array<string, array<array-key, mixed>>
     */
    private function dataMaps(array $tree): array
    {
        $maps = [];
        if (is_array($tree['data'] ?? null)) {
            $maps['data'] = $tree['data'];
        }
        $android = $tree['android'] ?? null;
        if (is_array($android) && is_array($android['data'] ?? null)) {
            $maps['android.data'] = $android['data'];
        }
        $v2 = $tree['android_v2'] ?? null;
        if (is_array($v2) && is_array($v2['data'] ?? null)) {
            $maps['android_v2.data'] = $v2['data'];
        }

        return $maps;
    }

    /**
     * Put a package-owned data entry in every data map in play; refuse to override a different user value.
     *
     * @param  array<array-key, mixed>  $tree
     */
    private function putData(array &$tree, string $key, string $value, string $what): void
    {
        $existing = $this->dataMaps($tree);
        foreach ($existing as $label => $map) {
            if (array_key_exists($key, $map) && $map[$key] !== $value) {
                throw new InvalidMessageException("{$label} key \"{$key}\" collides with the {$what}, which the package writes there.");
            }
        }

        $data = is_array($tree['data'] ?? null) ? $tree['data'] : [];
        $data[$key] = $value;
        $tree['data'] = $data;

        foreach (['android', 'android_v2'] as $config) {
            $section = $tree[$config] ?? null;
            if (is_array($section) && is_array($section['data'] ?? null)) {
                $section['data'][$key] = $value;
                $tree[$config] = $section;
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $tree
     * @param  list<string>  $warnings
     */
    private function applyExpiry(array &$tree, CompileContext $context, array &$warnings): void
    {
        if ($context->expiresAt === null) {
            return;
        }

        $key = array_key_exists('android_v2', $tree) ? 'android_v2' : 'android';
        $section = is_array($tree[$key] ?? null) ? $tree[$key] : [];
        if (array_key_exists('ttl', $section)) {
            return; // an explicit ttl set by the caller wins
        }

        $micros = static fn (\DateTimeImmutable $t): int => $t->getTimestamp() * 1_000_000 + (int) $t->format('u');
        $remaining = max(0, intdiv($micros($context->expiresAt) - $micros($context->now), 1_000_000));
        if ($micros($context->expiresAt) < $micros($context->now)) {
            $remaining = 0;
        }

        if ($remaining > self::MAX_TTL_SECONDS) {
            $warnings[] = 'The remaining lifetime exceeds the FCM maximum of 4 weeks: ttl capped at 2419200s.';
            $remaining = self::MAX_TTL_SECONDS;
        }

        $section['ttl'] = $remaining.'s';
        $tree[$key] = $section;
    }

    /**
     * Shorten only title/body: bodies before titles, platform overrides before the shared notification. Data, keys and every other field are untouched.
     *
     * @param  array<array-key, mixed>  $tree
     * @param  list<string>  $warnings
     * @return array<array-key, mixed>
     */
    private function truncateText(array $tree, int $limit, int $before, array &$warnings): array
    {
        $paths = [
            'android.notification' => ['android', 'notification'],
            'android_v2.remote_notification.notification' => ['android_v2', 'remote_notification', 'notification'],
            'notification' => ['notification'],
        ];

        $fields = [];
        foreach (['body', 'title'] as $leaf) {
            foreach ($paths as $name => $path) {
                $value = self::getPath($tree, [...$path, $leaf]);
                if (is_string($value)) {
                    $fields["{$name}.{$leaf}"] = $value;
                }
            }
        }

        $fitted = TextFitter::fit($fields, $limit, fn (array $values): int => FcmPayloadLimits::measure($this->withText($tree, $paths, $values)));
        if ($fitted === null) {
            throw new PayloadTooLargeException("The FCM payload is {$before} bytes and cannot fit in {$limit} bytes even with title and body truncated: the remaining content (data) is never shortened.");
        }

        $changed = array_keys(array_filter($fitted, static fn (string $v, string $name): bool => $v !== ($fields[$name] ?? ''), ARRAY_FILTER_USE_BOTH));
        $warnings[] = "Truncated to fit {$limit} bytes (was {$before}): ".implode(', ', $changed).'.';

        return $this->withText($tree, $paths, $fitted);
    }

    /**
     * @param  array<array-key, mixed>  $tree
     * @param  array<string, list<string>>  $paths
     * @param  array<string, string>  $values  "<paths key>.<leaf>" => text
     * @return array<array-key, mixed>
     */
    private function withText(array $tree, array $paths, array $values): array
    {
        foreach ($values as $name => $value) {
            $split = (int) strrpos($name, '.');
            $tree = self::setPath($tree, [...$paths[substr($name, 0, $split)], substr($name, $split + 1)], $value);
        }

        return $tree;
    }

    /**
     * @param  array<array-key, mixed>  $tree
     */
    private function encode(string $targetField, string $target, array $tree, bool $validateOnly): string
    {
        $message = [$targetField => $target] + $tree;

        foreach (['data'] as $map) {
            if (array_key_exists($map, $message) && is_array($message[$map])) {
                $message[$map] = JsonEncoder::map($message[$map]);
            }
        }
        foreach (['android', 'android_v2', 'apns', 'fcm_options'] as $section) {
            if (! array_key_exists($section, $message) || ! is_array($message[$section])) {
                continue;
            }
            $value = $message[$section];
            if (is_array($value['data'] ?? null)) {
                $value['data'] = JsonEncoder::map($value['data']);
            }
            if (is_array($value['headers'] ?? null)) {
                $value['headers'] = JsonEncoder::map($value['headers']);
            }
            if (is_array($value['payload'] ?? null)) {
                $value['payload'] = JsonEncoder::map($value['payload']);
            }
            $message[$section] = $value === [] ? JsonEncoder::map([]) : $value;
        }

        $body = ['message' => $message];
        $validateOnly && $body['validate_only'] = true;

        return JsonEncoder::encode($body);
    }

    /**
     * Recursive merge where `$over` wins: maps merge key by key, lists and scalars are replaced.
     *
     * @param  array<array-key, mixed>  $base
     * @param  array<array-key, mixed>  $over
     * @return array<array-key, mixed>
     */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            $existing = $base[$key] ?? null;
            if (is_array($value) && is_array($existing) && ! array_is_list($value) && ! array_is_list($existing)) {
                $base[$key] = self::merge($existing, $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * @param  array<array-key, mixed>  $tree
     * @param  list<string>  $path
     */
    private static function getPath(array $tree, array $path): mixed
    {
        $node = $tree;
        foreach ($path as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * @param  array<array-key, mixed>  $tree
     * @param  list<string>  $path
     * @return array<array-key, mixed>
     */
    private static function setPath(array $tree, array $path, string $value): array
    {
        $head = array_shift($path);
        if ($head === null) {
            return $tree;
        }

        if ($path === []) {
            $tree[$head] = $value;

            return $tree;
        }

        $child = $tree[$head] ?? [];
        /** @var array<array-key, mixed> $child */
        $child = is_array($child) ? $child : [];
        $tree[$head] = self::setPath($child, $path, $value);

        return $tree;
    }
}
