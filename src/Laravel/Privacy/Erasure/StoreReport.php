<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Privacy\Erasure;

/** What happened to one store. Counters and short fixed text only: never a token, an id or an exception message. */
final readonly class StoreReport
{
    public function __construct(
        public string $store,
        public StoreStatus $status,
        public int $affected = 0,
        public string $detail = '',
    ) {}

    public static function done(string $store, int $affected, string $detail = ''): self
    {
        return new self($store, StoreStatus::Done, $affected, $detail);
    }

    public static function notPresent(string $store, string $detail): self
    {
        return new self($store, StoreStatus::NotPresent, 0, $detail);
    }

    public static function failed(string $store, string $detail): self
    {
        return new self($store, StoreStatus::Failed, 0, $detail);
    }

    /** @return array{store: string, status: string, affected: int, detail: string} */
    public function toArray(): array
    {
        return ['store' => $this->store, 'status' => $this->status->value, 'affected' => $this->affected, 'detail' => $this->detail];
    }
}
