<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy;

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;

/** Turns the configured value (`auto | live | simulate`) and the application environment into the effective mode. */
final readonly class ModeResolver
{
    public const PRODUCTION = 'production';

    public function resolve(string $configured, string $applicationEnvironment): ExecutionMode
    {
        return match (strtolower(trim($configured))) {
            'live' => ExecutionMode::Live,
            'simulate' => ExecutionMode::Simulate,
            'auto' => $applicationEnvironment === self::PRODUCTION ? ExecutionMode::Live : ExecutionMode::Simulate,
            default => throw new ConfigurationException('Invalid mobile-push mode: expected "auto", "live" or "simulate".'),
        };
    }
}
