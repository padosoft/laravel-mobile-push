<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Outcome;

/**
 * Why a destination got its status. The enum cannot carry provider text, so the raw string is a separate,
 * sanitized field (printable ASCII, max 120 chars).
 */
final readonly class Reason
{
    public ?string $rawReason;

    public function __construct(
        public ReasonCode $code,
        ?string $rawReason = null,
        public ?string $hint = null,
    ) {
        $this->rawReason = $rawReason === null ? null : self::sanitize($rawReason);
    }

    public static function none(): self
    {
        return new self(ReasonCode::None);
    }

    public static function of(ReasonCode $code, ?string $rawReason = null, ?string $hint = null): self
    {
        return new self($code, $rawReason, $hint);
    }

    public function withHint(?string $hint): self
    {
        return new self($this->code, $this->rawReason, $hint);
    }

    private static function sanitize(string $raw): string
    {
        return substr((string) preg_replace('/[^\x20-\x7E]/', '?', $raw), 0, 120);
    }
}
