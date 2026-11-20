<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Topics;

/**
 * One installation's subscription to one topic (Discovery schema `TopicSubscription`: name, topicName, createTime).
 * The resource `name` embeds the registration id, so it is deliberately not kept here.
 */
final readonly class TopicSubscription
{
    public function __construct(
        public string $topicName,
        public ?\DateTimeImmutable $createTime = null,
        /** True when `subscribe()` found the subscription already in place (ALREADY_EXISTS treated as success). */
        public bool $alreadyExisted = false,
    ) {}
}
