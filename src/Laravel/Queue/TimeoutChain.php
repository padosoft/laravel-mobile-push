<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Queue;

/** The computed timeout chain: the values used and the verdict on every link (for the doctor command). */
final readonly class TimeoutChain
{
    /**
     * @param  array<string, float|null>  $values  name => seconds (null = unknown)
     * @param  list<TimeoutLink>  $links
     * @param  array<string, string>  $sources  name => where the value was read
     */
    public function __construct(
        public array $values,
        public array $links,
        public array $sources = [],
    ) {}

    /** @return list<TimeoutLink> */
    public function violations(): array
    {
        return $this->only(ChainStatus::Violation);
    }

    /** @return list<TimeoutLink> */
    public function notVerifiable(): array
    {
        return $this->only(ChainStatus::NotVerifiable);
    }

    /** No link is violated (some may still be unverifiable). */
    public function isConsistent(): bool
    {
        return $this->violations() === [];
    }

    /** Every applicable link was checked and holds. */
    public function isVerified(): bool
    {
        return $this->violations() === [] && $this->notVerifiable() === [];
    }

    /** @return list<TimeoutLink> */
    private function only(ChainStatus $status): array
    {
        return array_values(array_filter($this->links, static fn (TimeoutLink $l): bool => $l->status === $status));
    }
}
