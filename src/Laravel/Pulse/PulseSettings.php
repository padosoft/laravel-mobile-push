<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Pulse;

use Illuminate\Contracts\Config\Repository;

/**
 * Pulse integration settings, read live from `mobile-push.pulse.*` with explicit defaults.
 *
 * Keys: enabled (bool, false), sample_rate (float 0..1, 1.0), max_connections (int, 50).
 */
final readonly class PulseSettings
{
    public function __construct(
        public bool $enabled = false,
        public float $sampleRate = 1.0,
        public int $maxConnections = 50,
    ) {}

    public static function fromConfig(Repository $config): self
    {
        $rate = $config->get('mobile-push.pulse.sample_rate', 1.0);
        $max = $config->get('mobile-push.pulse.max_connections', 50);

        return new self(
            filter_var($config->get('mobile-push.pulse.enabled', false), FILTER_VALIDATE_BOOLEAN),
            is_numeric($rate) ? max(0.0, min(1.0, (float) $rate)) : 1.0,
            is_numeric($max) ? max(1, (int) $max) : 50,
        );
    }
}
