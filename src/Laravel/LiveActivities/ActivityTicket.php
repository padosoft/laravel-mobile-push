<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities;

use Padosoft\MobilePush\Laravel\LiveActivities\Transition\TransitionPayload;

/**
 * What a queued job needs to apply one transition later: the row, the ticket number handed out at dispatch, the
 * row version seen at dispatch (informational) and the payload captured at dispatch. Plain data, serializable.
 *
 * Replaying is safe at any time: a ticket older than one already applied or pending is skipped, and nothing is
 * sent after an end or the local expiry (ActivityManager::replay()).
 */
final readonly class ActivityTicket
{
    public const TARGET_ACTIVITY = 'activity';

    public const TARGET_CHANNEL = 'channel';

    public function __construct(
        /** self::TARGET_ACTIVITY or self::TARGET_CHANNEL */
        public string $target,
        public string $id,
        public int $seq,
        public int $version,
        public TransitionPayload $payload,
    ) {
        if (! in_array($target, [self::TARGET_ACTIVITY, self::TARGET_CHANNEL], true)) {
            throw new \InvalidArgumentException('A ticket targets an activity or a channel.');
        }
    }

    /** @return array{target: string, id: string, seq: int, version: int, payload: array<string, mixed>} */
    public function toArray(): array
    {
        return ['target' => $this->target, 'id' => $this->id, 'seq' => $this->seq, 'version' => $this->version, 'payload' => $this->payload->toArray()];
    }

    /** @param  array<array-key, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $payload = $data['payload'] ?? null;

        return new self(
            is_string($data['target'] ?? null) ? $data['target'] : '',
            is_string($data['id'] ?? null) ? $data['id'] : '',
            is_int($data['seq'] ?? null) ? $data['seq'] : 0,
            is_int($data['version'] ?? null) ? $data['version'] : 0,
            TransitionPayload::fromArray(is_array($payload) ? $payload : []),
        );
    }
}
