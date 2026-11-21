<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

use Illuminate\Contracts\Config\Repository;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;

/**
 * Campaign settings, read with explicit defaults. Keys (under `mobile-push.`): campaigns.enabled, campaigns.table,
 * campaigns.connection, campaigns.slice, campaigns.default_rate_per_minute, campaigns.retention_days,
 * campaigns.sweeper.schedule, campaigns.audiences; and delivery.profile.
 */
final readonly class CampaignSettings
{
    public const MAX_SLICE = 5_000;

    /**
     * @param  array<mixed>  $audiences  alias => Audience class
     */
    public function __construct(
        public bool $enabled = false,
        public string $table = 'mobile_push_campaigns',
        /** Database connection of the campaigns table (null = the default connection). */
        public ?string $connection = null,
        /** Destinations read and written per planner slice (one job per slice). */
        public int $slice = 1000,
        /** Planner pacing when a campaign sets none (null = as fast as the planner can write). */
        public ?int $defaultRatePerMinute = null,
        /** Finished campaigns are pruned after this many days; also the ledger dedupe window of a campaign effect. */
        public int $retentionDays = 30,
        /** Schedule SweepCampaignsJob every minute (stalled planners, completion detection). */
        public bool $scheduleSweeper = true,
        public array $audiences = [],
        /** mobile-push.delivery.profile */
        public string $profile = 'light',
    ) {
        if ($slice < 1 || $slice > self::MAX_SLICE) {
            throw new ConfigurationException('mobile-push.campaigns.slice must be between 1 and '.self::MAX_SLICE.'.');
        }
        if ($retentionDays < 1) {
            throw new ConfigurationException('mobile-push.campaigns.retention_days must be at least 1.');
        }
        if ($table === '') {
            throw new ConfigurationException('mobile-push.campaigns.table cannot be empty.');
        }
        try {
            Pacing::assertRate($defaultRatePerMinute);
        } catch (\InvalidArgumentException $e) {
            throw new ConfigurationException('mobile-push.campaigns.default_rate_per_minute: '.$e->getMessage());
        }
    }

    public static function fromConfig(Repository $config): self
    {
        $rate = $config->get('mobile-push.campaigns.default_rate_per_minute');
        $audiences = $config->get('mobile-push.campaigns.audiences', []);

        return new self(
            enabled: self::bool($config->get('mobile-push.campaigns.enabled', false), false),
            table: self::string($config->get('mobile-push.campaigns.table')) ?? 'mobile_push_campaigns',
            connection: self::string($config->get('mobile-push.campaigns.connection')),
            slice: is_numeric($v = $config->get('mobile-push.campaigns.slice', 1000)) ? (int) $v : 1000,
            defaultRatePerMinute: is_numeric($rate) ? (int) $rate : null,
            retentionDays: is_numeric($v = $config->get('mobile-push.campaigns.retention_days', 30)) ? (int) $v : 30,
            scheduleSweeper: self::bool($config->get('mobile-push.campaigns.sweeper.schedule', true), true),
            audiences: is_array($audiences) ? $audiences : [],
            profile: self::string($config->get('mobile-push.delivery.profile')) ?? 'light',
        );
    }

    public function durable(): bool
    {
        return $this->profile === 'durable';
    }

    /** Campaigns can be created and planned. */
    public function assertUsable(): void
    {
        if (! $this->durable()) {
            throw new ConfigurationException('Campaigns need the durable delivery profile: set mobile-push.delivery.profile to "durable" (MOBILE_PUSH_DELIVERY_PROFILE=durable) and run the ledger migrations. The light profile keeps no ledger, so a large audience could not be planned, resumed or counted.');
        }
        if (! $this->enabled) {
            throw new ConfigurationException('Campaigns are disabled: set mobile-push.campaigns.enabled to true (MOBILE_PUSH_CAMPAIGNS_ENABLED=true) and run the campaigns migration.');
        }
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function bool(mixed $value, bool $default): bool
    {
        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
