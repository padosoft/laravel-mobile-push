<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy;

use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;

/** The verdict of SafeModeGuard: may this operation open a connection? */
final readonly class SafeModeDecision
{
    private function __construct(
        public bool $allowed,
        public ExecutionMode $mode,
        public ReasonCode $reasonCode,
        public ?string $hint,
    ) {}

    public static function allow(ExecutionMode $mode): self
    {
        return new self(true, $mode, ReasonCode::None, null);
    }

    public static function simulate(string $hint): self
    {
        return new self(false, ExecutionMode::Simulate, ReasonCode::Simulated, $hint);
    }

    public static function block(ExecutionMode $mode, string $hint): self
    {
        return new self(false, $mode, ReasonCode::PolicySafeMode, $hint);
    }

    public function isSimulated(): bool
    {
        return $this->reasonCode === ReasonCode::Simulated;
    }

    public function isBlocked(): bool
    {
        return $this->reasonCode === ReasonCode::PolicySafeMode;
    }

    public function reason(): Reason
    {
        return new Reason($this->reasonCode, null, $this->hint);
    }
}
