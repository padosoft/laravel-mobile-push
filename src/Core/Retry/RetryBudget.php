<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Retry;

/**
 * The retry state of ONE destination for ONE effect. Immutable: every change returns a new instance, which the
 * caller persists (ledger row in the durable profile, job payload in the light profile).
 *
 * The absolute deadline is min(logical expiry of the message, first attempt + maxElapsed): no attempt is ever
 * planned at or after it, whoever plans it.
 */
final readonly class RetryBudget
{
    private const VERSION = 1;

    private const TIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    private function __construct(
        /** Attempts already made (each classified exchange counts as one). */
        public int $attempts,
        public \DateTimeImmutable $startedAt,
        /** startedAt + maxElapsed. */
        public \DateTimeImmutable $elapsedDeadline,
        /** Logical expiry of the message (after it, sending is pointless), if any. */
        public ?\DateTimeImmutable $expiresAt,
        /** At least one attempt ended with an unknown outcome: the provider MAY already have accepted it. */
        public bool $unknownOutcomeSeen = false,
        /** Immediate retries already granted after a provider-token refresh. */
        public int $tokenRefreshes = 0,
    ) {}

    /**
     * Open the budget at the first attempt.
     */
    public static function start(RetryPolicy $policy, \DateTimeImmutable $now, ?\DateTimeImmutable $expiresAt = null): self
    {
        $now = self::utc($now);

        return new self(
            0,
            $now,
            $now->modify(sprintf('+%d seconds', $policy->maxElapsedSeconds)),
            $expiresAt === null ? null : self::utc($expiresAt),
        );
    }

    public function deadline(): \DateTimeImmutable
    {
        return $this->expiresAt !== null && $this->expiresAt <= $this->elapsedDeadline
            ? $this->expiresAt
            : $this->elapsedDeadline;
    }

    /** True when the binding deadline is the message's logical expiry (running past it means Expired). */
    public function deadlineIsExpiry(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $this->elapsedDeadline;
    }

    /**
     * The logical expiry has passed: the sender must not touch the network for this destination
     * (see RetryPlanner::beforeAttempt()).
     */
    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $now >= $this->expiresAt;
    }

    public function withAttempt(): self
    {
        return new self($this->attempts + 1, $this->startedAt, $this->elapsedDeadline, $this->expiresAt, $this->unknownOutcomeSeen, $this->tokenRefreshes);
    }

    public function withUnknownOutcome(): self
    {
        return new self($this->attempts, $this->startedAt, $this->elapsedDeadline, $this->expiresAt, true, $this->tokenRefreshes);
    }

    public function withTokenRefresh(): self
    {
        return new self($this->attempts, $this->startedAt, $this->elapsedDeadline, $this->expiresAt, $this->unknownOutcomeSeen, $this->tokenRefreshes + 1);
    }

    /**
     * @return array{v: int, attempts: int, started_at: string, elapsed_deadline: string, expires_at: string|null, unknown_outcome_seen: bool, token_refreshes: int}
     */
    public function toArray(): array
    {
        return [
            'v' => self::VERSION,
            'attempts' => $this->attempts,
            'started_at' => $this->startedAt->format(self::TIME_FORMAT),
            'elapsed_deadline' => $this->elapsedDeadline->format(self::TIME_FORMAT),
            'expires_at' => $this->expiresAt?->format(self::TIME_FORMAT),
            'unknown_outcome_seen' => $this->unknownOutcomeSeen,
            'token_refreshes' => $this->tokenRefreshes,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  as produced by toArray()
     *
     * @throws \InvalidArgumentException when the stored state is not a budget of a known version
     */
    public static function fromArray(array $data): self
    {
        if (($data['v'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('Unsupported retry budget version.');
        }

        $attempts = $data['attempts'] ?? null;
        $refreshes = $data['token_refreshes'] ?? null;
        $unknown = $data['unknown_outcome_seen'] ?? null;
        if (! is_int($attempts) || $attempts < 0 || ! is_int($refreshes) || $refreshes < 0 || ! is_bool($unknown)) {
            throw new \InvalidArgumentException('Malformed retry budget counters.');
        }

        $expires = $data['expires_at'] ?? null;

        return new self(
            $attempts,
            self::parse($data['started_at'] ?? null),
            self::parse($data['elapsed_deadline'] ?? null),
            $expires === null ? null : self::parse($expires),
            $unknown,
            $refreshes,
        );
    }

    private static function parse(mixed $value): \DateTimeImmutable
    {
        $parsed = is_string($value) ? \DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $value) : false;
        if ($parsed === false) {
            throw new \InvalidArgumentException('Malformed retry budget timestamp.');
        }

        return self::utc($parsed);
    }

    private static function utc(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return $time->setTimezone(new \DateTimeZone('UTC'));
    }
}
