<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy\Frequency;

/** The outcome of one cap decision for one subject and one effect; the same effect always gets the same one. */
final readonly class FrequencyDecision
{
    private function __construct(
        public bool $allowed,
        public ?string $window,
        public ?int $limit,
    ) {}

    public static function allowed(): self
    {
        return new self(true, null, null);
    }

    public static function capped(string $window, int $limit): self
    {
        return new self(false, $window, $limit);
    }

    /** "3 per day", or null when allowed. */
    public function describeLimit(): ?string
    {
        return $this->allowed ? null : $this->limit.' per '.$this->window;
    }

    public function encode(): string
    {
        return $this->allowed ? 'a' : 'd:'.$this->window.':'.$this->limit;
    }

    public static function decode(?string $encoded): ?self
    {
        if ($encoded === 'a') {
            return self::allowed();
        }
        if ($encoded !== null && preg_match('/^d:([a-z]+):([0-9]+)$/', $encoded, $m) === 1) {
            return self::capped($m[1], (int) $m[2]);
        }

        return null;
    }
}
