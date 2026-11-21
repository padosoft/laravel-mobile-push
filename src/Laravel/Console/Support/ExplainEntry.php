<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;

/** One explainable reason: an APNs reason string, an FCM error code or status, or a package ReasonCode. */
final readonly class ExplainEntry
{
    /**
     * @param  'apns'|'fcm'|'package'  $provider
     * @param  list<string>  $behaviour  what the package does about it
     */
    public function __construct(
        public string $provider,
        public string $source,
        public string $key,
        public ?int $httpStatus,
        public ReasonCode $code,
        public ?SendStatus $status,
        public string $meaning,
        public array $behaviour,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'source' => $this->source,
            'reason' => $this->key,
            'http_status' => $this->httpStatus,
            'reason_code' => $this->code->value,
            'status' => $this->status?->value,
            'meaning' => $this->meaning,
            'behaviour' => $this->behaviour,
        ];
    }
}
