<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns;

use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityEvent;
use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityMessage;
use Padosoft\MobilePush\Core\Apns\Message\Alert;
use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;
use Padosoft\MobilePush\Core\Apns\Message\Sound;
use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Exceptions\PayloadTooLargeException;
use Padosoft\MobilePush\Core\Exceptions\UnsupportedCapabilityException;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\CompileContext;
use Padosoft\MobilePush\Core\Message\CompiledMessage;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\MessageCompiler;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Message\Support\JsonEncoder;
use Padosoft\MobilePush\Core\Message\Support\TextFitter;

/**
 * Compiles PushMessage, ApnsMessage and LiveActivityMessage into APNs headers + JSON body.
 *
 * Pure: no I/O and no clock other than `CompileContext::$now`. Never modifies its input messages.
 * Headers never include authorization. The driver chooses the path from `meta['route']`
 * (`device`: /3/device/<token>, `broadcast`: /4/broadcasts/apps/<bundle id>).
 */
final class ApnsCompiler implements MessageCompiler
{
    /** Custom keys the compiler itself writes for the unified message. */
    private const KEY_IMAGE = 'image_url';

    private const KEY_DEEP_LINK = 'deep_link';

    private const KEY_RECEIPT = 'mp_r';

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function supports(Message $message): bool
    {
        return $message instanceof PushMessage || $message instanceof ApnsMessage || $message instanceof LiveActivityMessage;
    }

    public function compile(Message $message, Destination $destination, CompileContext $context): CompiledMessage
    {
        if ($destination->kind()->provider() !== Provider::Apns) {
            throw new InvalidDestinationException('An FCM destination cannot be sent through APNs.');
        }
        if ($context->binding->provider !== Provider::Apns) {
            throw new InvalidDestinationException('The binding of this compilation is not an APNs binding.');
        }

        $warnings = [];
        $meta = [];

        if ($message instanceof LiveActivityMessage) {
            return $this->compileLiveActivity($message, $destination, $context);
        }

        if ($message instanceof PushMessage) {
            $native = $this->fromUnified($message, $context, $warnings, $meta);
            if ($message->apnsOverlay !== null) {
                $native = $native->overlaidWith($message->apnsOverlay);
            }
            $truncate = $message->truncate || $native->truncate;
        } elseif ($message instanceof ApnsMessage) {
            $native = $message;
            $truncate = $message->truncate;
        } else {
            throw new UnsupportedCapabilityException('The APNs compiler cannot compile a message of type '.$message::class.'.');
        }

        return $this->compileNotification($native, $truncate, $destination, $context, $warnings, $meta);
    }

    /**
     * Map the unified message onto a native base. `policy`, `incrementBadge` and `bypassFrequencyCap` are
     * decisions taken before sending and are never sent to the device.
     *
     * @param  list<string>  $warnings
     * @param  array<string, string>  $meta
     */
    private function fromUnified(PushMessage $p, CompileContext $context, array &$warnings, array &$meta): ApnsMessage
    {
        $native = new ApnsMessage;

        $silent = $p->silent;
        if ($silent) {
            $conflicts = array_keys(array_filter([
                'title' => $p->title !== null,
                'subtitle' => $p->subtitle !== null,
                'body' => $p->body !== null,
                'sound' => $p->sound !== null,
                'badge' => $p->badge !== null,
                'critical' => $p->critical,
                'timeSensitive' => $p->timeSensitive,
            ]));
            if ($conflicts !== []) {
                if (! $p->lenient) {
                    throw new InvalidMessageException('A silent message cannot carry: '.implode(', ', $conflicts).'. Remove them or drop silent().');
                }
                $warnings[] = 'silent message: dropped '.implode(', ', $conflicts).'.';
                $p = clone $p;
                $p->title = $p->subtitle = $p->body = $p->sound = $p->badge = null;
                $p->critical = $p->timeSensitive = false;
            }
            if ($p->image !== null) {
                if (! $p->lenient) {
                    throw new UnsupportedCapabilityException('An image needs a visible notification: it cannot be combined with silent().');
                }
                $warnings[] = 'silent message: dropped image.';
                $p = clone $p;
                $p->image = null;
            }

            $native->pushType = PushType::Background;
            $native->priority = Priority::Normal;
            $native->contentAvailable = true;
        } else {
            $native->pushType = PushType::Alert;
            $native->priority = Priority::fromMessagePriority($p->priority);
            if ($p->title !== null || $p->subtitle !== null || $p->body !== null) {
                $native->alert = Alert::make($p->title, $p->body, $p->subtitle);
            }
            $native->badge = $p->badge;
            if ($p->critical && $p->timeSensitive) {
                throw new InvalidMessageException('critical() and timeSensitive() are mutually exclusive interruption levels.');
            }
            if ($p->critical) {
                $native->sound = Sound::critical($p->sound ?? 'default');
                $native->interruptionLevel = InterruptionLevel::Critical;
            } else {
                $native->sound = $p->sound === null ? null : Sound::named($p->sound);
                $native->interruptionLevel = $p->timeSensitive ? InterruptionLevel::TimeSensitive : null;
            }
        }

        $native->category = $p->actionCategory;
        $native->threadId = $p->group;
        $native->collapseId = $p->collapseKey;
        $native->mutableContent = $p->mutableContent ? true : null;

        foreach ($p->data as $key => $value) {
            $native->custom[$key] = $value;
        }

        $generated = [];
        if ($p->image !== null) {
            $generated[self::KEY_IMAGE] = $p->image;
            $native->mutableContent = true;
            $meta['requires_app_code'] = 'image';
        }
        if ($p->deepLink !== null) {
            $generated[self::KEY_DEEP_LINK] = $p->deepLink;
        }
        foreach ($generated as $key => $value) {
            if (array_key_exists($key, $native->custom)) {
                throw new InvalidMessageException("The custom data key \"{$key}\" is reserved: the compiler writes it for the unified message.");
            }
            $native->custom[$key] = $value;
        }

        return $native;
    }

    /**
     * @param  list<string>  $warnings
     * @param  array<string, string>  $meta
     */
    private function compileNotification(
        ApnsMessage $m,
        bool $truncate,
        Destination $destination,
        CompileContext $context,
        array $warnings,
        array $meta,
    ): CompiledMessage {
        $type = $m->pushType ?? PushType::Alert;
        if ($type === PushType::LiveActivity) {
            throw new InvalidMessageException('Live Activities are sent with LiveActivityMessage, not ApnsMessage.');
        }

        $this->assertNotificationDestination($type, $destination);
        $priority = $m->priority ?? ($type->requiresLowPriority() ? Priority::Normal : Priority::High);
        $this->validateNotification($m, $type, $priority);

        $topic = TopicResolver::resolve($context->binding->appId, $type, $m->topic);
        $marker = $context->receiptMarker;

        $build = fn (?Alert $alert): string => JsonEncoder::encode($this->notificationPayload($m, $type, $alert, $marker));

        $limit = ApnsPayloadLimits::for($type, $destination->kind());
        $body = $this->fitToLimit($build, $m->alert, $limit, $truncate, $warnings);

        $headers = [
            'apns-push-type' => $type->value,
            'apns-topic' => $topic,
            'apns-priority' => (string) $priority->value,
        ];
        if ($context->expiresAt !== null) {
            $headers['apns-expiration'] = (string) max(0, $context->expiresAt->getTimestamp());
        }
        if ($m->collapseId !== null) {
            $headers['apns-collapse-id'] = $m->collapseId;
        }
        $this->addMessageId($headers, 'apns-id', $context);

        return new CompiledMessage(
            headers: $headers,
            body: $body,
            payloadBytes: strlen($body),
            limitBytes: $limit,
            warnings: $warnings,
            meta: [...$meta, 'push_type' => $type->value, 'topic' => $topic, 'priority' => (string) $priority->value, 'route' => 'device'],
        );
    }

    private function assertNotificationDestination(PushType $type, Destination $destination): void
    {
        $expected = $type === PushType::Voip ? DestinationKind::ApnsVoipToken : DestinationKind::ApnsDeviceToken;
        if ($destination->kind() !== $expected) {
            throw new InvalidDestinationException(sprintf(
                'The "%s" push type must be sent to %s, not to %s.',
                $type->value,
                $expected->value,
                $destination->kind()->value,
            ));
        }
    }

    private function validateNotification(ApnsMessage $m, PushType $type, Priority $priority): void
    {
        $hasAlert = $m->alert !== null && ! $m->alert->isEmpty();

        if ($type === PushType::Background) {
            if ($priority !== Priority::Normal) {
                throw new InvalidMessageException('A background push must use priority 5: APNs treats priority 10 as an error.');
            }
            $visible = array_keys(array_filter([
                'alert' => $hasAlert,
                'sound' => $m->sound !== null,
                'badge' => $m->badge !== null,
                'interruption-level' => $m->interruptionLevel !== null,
            ]));
            if ($visible !== []) {
                throw new InvalidMessageException('A background push cannot carry: '.implode(', ', $visible).'.');
            }
            if ($m->contentAvailable === false) {
                throw new InvalidMessageException('A background push needs content-available 1.');
            }
        }

        if ($type === PushType::Alert && ! $hasAlert && $m->badge === null && $m->sound === null) {
            throw new InvalidMessageException('An alert push needs an alert, a badge or a sound. Use a background push for silent content.');
        }

        $criticalSound = $m->sound !== null && $m->sound->critical;
        $criticalLevel = $m->interruptionLevel === InterruptionLevel::Critical;
        if ($criticalSound !== $criticalLevel) {
            throw new InvalidMessageException('A critical alert needs both the critical sound dictionary and interruption-level "critical".');
        }

        if ($m->badge !== null && $m->badge < 0) {
            throw new InvalidMessageException('The badge cannot be negative.');
        }
        if ($m->relevanceScore !== null && (! is_finite($m->relevanceScore) || $m->relevanceScore < 0.0 || $m->relevanceScore > 1.0)) {
            throw new InvalidMessageException('The relevance score of a notification must be between 0 and 1.');
        }

        if ($m->collapseId !== null) {
            $this->assertCollapseId($m->collapseId);
        }

        foreach (array_keys($m->custom) as $key) {
            if (! is_string($key) || $key === '') {
                throw new InvalidMessageException('Custom data keys must be non-empty strings.');
            }
            if ($key === 'aps') {
                throw new InvalidMessageException('The custom key "aps" is reserved by APNs.');
            }
        }
    }

    private function assertCollapseId(string $id): void
    {
        if ($id === '' || strlen($id) > 64) {
            throw new InvalidMessageException('The collapse id must be 1 to 64 bytes.');
        }
        if (preg_match('//u', $id) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $id) === 1) {
            throw new InvalidMessageException('The collapse id must be valid UTF-8 without control characters.');
        }
    }

    /** @return array<string, mixed> */
    private function notificationPayload(ApnsMessage $m, PushType $type, ?Alert $alert, ?string $marker): array
    {
        $aps = [];
        if ($alert !== null && ! $alert->isEmpty()) {
            $aps['alert'] = $alert->toArray();
        }
        if ($m->badge !== null) {
            $aps['badge'] = $m->badge;
        }
        if ($m->sound !== null) {
            $aps['sound'] = $m->sound->toValue();
        }
        if ($m->threadId !== null) {
            $aps['thread-id'] = $m->threadId;
        }
        if ($m->category !== null) {
            $aps['category'] = $m->category;
        }
        if ($m->contentAvailable === true || ($type === PushType::Background && $m->contentAvailable === null)) {
            $aps['content-available'] = 1;
        }
        if ($m->mutableContent === true) {
            $aps['mutable-content'] = 1;
        }
        if ($m->targetContentId !== null) {
            $aps['target-content-id'] = $m->targetContentId;
        }
        if ($m->interruptionLevel !== null) {
            $aps['interruption-level'] = $m->interruptionLevel->value;
        }
        if ($m->relevanceScore !== null) {
            $aps['relevance-score'] = $m->relevanceScore;
        }
        if ($m->filterCriteria !== null) {
            $aps['filter-criteria'] = $m->filterCriteria;
        }
        if ($m->contentChanged === true || ($m->contentChanged === null && in_array($type, [PushType::Widgets, PushType::Controls], true))) {
            $aps['content-changed'] = true;
        }

        $payload = ['aps' => JsonEncoder::map($aps)];
        foreach ($m->custom as $key => $value) {
            $payload[(string) $key] = $value;
        }

        if ($marker !== null) {
            if (array_key_exists(self::KEY_RECEIPT, $payload)) {
                throw new InvalidMessageException('The custom data key "'.self::KEY_RECEIPT.'" is reserved for the receipt marker.');
            }
            $payload[self::KEY_RECEIPT] = $marker;
        }

        return $payload;
    }

    /**
     * Encode and enforce the limit. With truncation opted in, only the alert's title/subtitle/body are
     * shortened (body first); everything else is left exactly as given.
     *
     * @param  callable(?Alert): string  $build
     * @param  list<string>  $warnings
     */
    private function fitToLimit(callable $build, ?Alert $alert, int $limit, bool $truncate, array &$warnings): string
    {
        $body = $build($alert);
        $size = strlen($body);
        if ($size <= $limit) {
            return $body;
        }

        /** @var array<string, string> $fields */
        $fields = [];
        if ($truncate && $alert !== null) {
            foreach (['body', 'subtitle', 'title'] as $name) {
                if ($alert->{$name} !== null && $alert->{$name} !== '') {
                    $fields[$name] = $alert->{$name};
                }
            }
        }

        if ($alert === null || $fields === []) {
            throw new PayloadTooLargeException(sprintf(
                'The APNs payload is %d bytes, %d over the %d byte limit.%s',
                $size,
                $size - $limit,
                $limit,
                $truncate ? ' There is no alert text to shorten.' : ' Shorten it or opt in to truncation of title/subtitle/body.',
            ));
        }

        $fitted = TextFitter::fit($fields, $limit, fn (array $values): int => strlen($build($this->alertWithFields($alert, $values))));
        if ($fitted === null) {
            throw new PayloadTooLargeException(sprintf('The APNs payload is %d bytes and does not fit the %d byte limit even after shortening the alert text.', $size, $limit));
        }

        foreach ($fitted as $name => $value) {
            $original = $fields[$name] ?? '';
            if ($value !== $original) {
                $warnings[] = sprintf('alert %s truncated from %d to %d bytes.', $name, strlen($original), strlen($value));
            }
        }

        return $build($this->alertWithFields($alert, $fitted));
    }

    /** @param  array<string, string>  $values  title/subtitle/body replacements; an empty string removes the field */
    private function alertWithFields(Alert $alert, array $values): Alert
    {
        foreach ($values as $name => $value) {
            $value = $value === '' ? null : $value;
            $alert = match ($name) {
                'title' => $alert->withTitle($value),
                'subtitle' => $alert->withSubtitle($value),
                default => $alert->withBody($value),
            };
        }

        return $alert;
    }

    private function compileLiveActivity(LiveActivityMessage $m, Destination $destination, CompileContext $context): CompiledMessage
    {
        $kind = $destination->kind();
        $isStart = $m->event === LiveActivityEvent::Start;
        $broadcast = $kind === DestinationKind::ApnsBroadcastChannel;

        if ($isStart) {
            if ($broadcast) {
                throw new InvalidDestinationException('A broadcast channel can never start a Live Activity: use an apns_push_to_start_token.');
            }
            if ($kind !== DestinationKind::ApnsPushToStartToken) {
                throw new InvalidDestinationException('A Live Activity start must be sent to apns_push_to_start_token, not to '.$kind->value.'.');
            }
        } elseif ($kind !== DestinationKind::ApnsLiveActivityToken && ! $broadcast) {
            throw new InvalidDestinationException('A Live Activity '.$m->event->value.' must be sent to apns_live_activity_token or apns_broadcast_channel, not to '.$kind->value.'.');
        }

        $this->validateLiveActivity($m, $isStart);

        $timestamp = $context->now->getTimestamp();
        $build = fn (?Alert $alert): string => JsonEncoder::encode(['aps' => JsonEncoder::map($this->liveActivityAps($m, $timestamp, $alert))]);

        $warnings = [];
        $limit = ApnsPayloadLimits::for(PushType::LiveActivity, $kind);
        $body = $this->fitToLimit($build, $m->alert, $limit, $m->truncate, $warnings);

        $bundleId = $context->binding->appId;
        $topic = TopicResolver::resolve($bundleId, PushType::LiveActivity);

        $headers = ['apns-push-type' => PushType::LiveActivity->value];
        if ($broadcast) {
            $headers['apns-channel-id'] = $destination->value();
        } else {
            $headers['apns-topic'] = $topic;
        }
        $headers['apns-priority'] = (string) $m->priority->value;

        if ($broadcast) {
            // Required on a broadcast publish. 0 is valid for every storage policy; a no-storage channel accepts nothing else.
            $expiration = $context->expiresAt === null ? 0 : max(0, $context->expiresAt->getTimestamp());
            if ($m->channelStoresNoMessages && $expiration !== 0) {
                $warnings[] = 'channel stores no messages: apns-expiration forced to 0.';
                $expiration = 0;
            }
            $headers['apns-expiration'] = (string) $expiration;
            $this->addMessageId($headers, 'apns-request-id', $context);
        } else {
            if ($context->expiresAt !== null) {
                $headers['apns-expiration'] = (string) max(0, $context->expiresAt->getTimestamp());
            }
            $this->addMessageId($headers, 'apns-id', $context);
        }

        $meta = [
            'push_type' => PushType::LiveActivity->value,
            'topic' => $topic,
            'priority' => (string) $m->priority->value,
            'route' => $broadcast ? 'broadcast' : 'device',
            'activity_event' => $m->event->value,
        ];
        if ($m->type !== null) {
            $meta['attributes_version'] = (string) $m->type->version();
        }

        return new CompiledMessage($headers, $body, strlen($body), $limit, $warnings, $meta);
    }

    private function validateLiveActivity(LiveActivityMessage $m, bool $isStart): void
    {
        if (! in_array($m->priority, [Priority::High, Priority::Normal], true)) {
            throw new InvalidMessageException('A Live Activity push must use priority 10 or 5.');
        }

        $hasAlert = $m->alert !== null && ! $m->alert->isEmpty();
        if ($m->sound !== null && ! $hasAlert) {
            throw new InvalidMessageException('A Live Activity sound is part of its alert: set an alert too.');
        }
        if ($m->sound !== null && $m->sound->critical) {
            throw new InvalidMessageException('A Live Activity alert cannot use a critical sound.');
        }
        if ($m->relevanceScore !== null && ! is_finite($m->relevanceScore)) {
            throw new InvalidMessageException('The relevance score must be a finite number.');
        }

        if ($isStart) {
            if ($m->type === null || trim($m->type->swiftName()) === '') {
                throw new InvalidMessageException('A Live Activity start needs the Swift attributes type name.');
            }
            if (! $hasAlert) {
                throw new InvalidMessageException('A Live Activity start must include an alert, so the person is told about the new activity.');
            }
            if ($m->inputPushToken && $m->inputPushChannel !== null) {
                throw new InvalidMessageException('Ask for an update token or subscribe to a channel when starting a Live Activity, not both.');
            }
            if ($m->inputPushChannel === '') {
                throw new InvalidMessageException('The input push channel id cannot be empty.');
            }
        } elseif ($m->inputPushToken || $m->inputPushChannel !== null) {
            throw new InvalidMessageException('input-push-token and input-push-channel only apply when starting a Live Activity.');
        }

        if ($m->dismissAt !== null && $m->event !== LiveActivityEvent::End) {
            throw new InvalidMessageException('dismissAt() only applies to a Live Activity end.');
        }
    }

    /** @return array<string, mixed> */
    private function liveActivityAps(LiveActivityMessage $m, int $timestamp, ?Alert $alert): array
    {
        $aps = ['timestamp' => $timestamp, 'event' => $m->event->value];

        if ($m->contentState !== null) {
            $aps['content-state'] = JsonEncoder::map($m->contentState);
        }
        if ($m->event === LiveActivityEvent::Start && $m->type !== null) {
            $aps['attributes-type'] = $m->type->swiftName();
            $aps['attributes'] = JsonEncoder::map($m->attributes);
        }
        if ($m->inputPushToken) {
            $aps['input-push-token'] = 1;
        }
        if ($m->inputPushChannel !== null) {
            $aps['input-push-channel'] = $m->inputPushChannel;
        }
        if ($m->staleAt !== null) {
            $aps['stale-date'] = $m->staleAt->getTimestamp();
        }
        if ($m->dismissAt !== null) {
            $aps['dismissal-date'] = $m->dismissAt->getTimestamp();
        }
        if ($m->relevanceScore !== null) {
            $aps['relevance-score'] = $m->relevanceScore;
        }
        if ($alert !== null && ! $alert->isEmpty()) {
            $alertMap = $this->liveActivityAlert($alert);
            if ($m->sound !== null) {
                // Apple's own Live Activity examples place the sound inside the alert dictionary.
                $alertMap['sound'] = $m->sound->toValue();
            }
            $aps['alert'] = $alertMap;
        }

        return $aps;
    }

    /**
     * Apple's Live Activity examples localize title/body as nested dictionaries
     * (`"title": {"loc-key": ..., "loc-args": [...]}`) instead of the flat `title-loc-key` of ordinary alerts.
     *
     * @return array<string, mixed>
     */
    private function liveActivityAlert(Alert $alert): array
    {
        $map = $alert->toArray();

        foreach ([['title', 'title-loc-key', 'title-loc-args'], ['body', 'loc-key', 'loc-args']] as [$field, $keyName, $argsName]) {
            if (! isset($map[$keyName])) {
                continue;
            }
            $localized = ['loc-key' => $map[$keyName]];
            if (isset($map[$argsName])) {
                $localized['loc-args'] = $map[$argsName];
            }
            unset($map[$keyName], $map[$argsName]);
            $map[$field] = $localized;
        }

        return $map;
    }

    /** @param  array<string, string>  $headers */
    private function addMessageId(array &$headers, string $name, CompileContext $context): void
    {
        if ($context->messageId === null) {
            return;
        }
        if (preg_match(self::UUID_PATTERN, $context->messageId) !== 1) {
            throw new InvalidMessageException('The message id must be a canonical lowercase UUID.');
        }
        $headers[$name] = $context->messageId;
    }
}
