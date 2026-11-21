<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Ops;

/** One line of the probe table: the token is always masked. */
final readonly class ProbeRow
{
    public function __construct(
        public string $deviceId,
        public string $maskedToken,
        public string $appId,
        public string $from,
        public ?string $to,
        public ProbeOutcome $outcome,
        public string $detail = '',
    ) {}

    public function with(ProbeOutcome $outcome, string $detail = ''): self
    {
        return new self($this->deviceId, $this->maskedToken, $this->appId, $this->from, $this->to, $outcome, $detail);
    }

    /** @return array{device: string, token: string, app_id: string, from: string, to: ?string, outcome: string, detail: string} */
    public function toArray(): array
    {
        return ['device' => $this->deviceId, 'token' => $this->maskedToken, 'app_id' => $this->appId, 'from' => $this->from, 'to' => $this->to, 'outcome' => $this->outcome->value, 'detail' => $this->detail];
    }
}
