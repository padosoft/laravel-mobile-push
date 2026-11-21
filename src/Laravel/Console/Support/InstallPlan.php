<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

use Padosoft\MobilePush\Laravel\Devices\DevicesModule;
use Padosoft\MobilePush\Laravel\Ledger\LedgerModule;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivitiesModule;
use Padosoft\MobilePush\Laravel\Logging\LoggingModule;

/** Which migrations belong to which optional module and delivery profile (discovered from the module classes). */
final class InstallPlan
{
    public const MODULES = ['devices', 'live-activities', 'logs', 'campaigns', 'receipts'];

    public const PROFILES = ['light', 'durable'];

    private const CAMPAIGNS_MODULE = 'Padosoft\MobilePush\Laravel\Campaigns\CampaignsModule';

    /**
     * The migration groups to publish.
     *
     * @param  list<string>  $modules
     * @return list<array{label: string, tag: string, suffixes: list<string>}>
     */
    public static function migrations(array $modules, string $profile): array
    {
        $groups = [];
        if (in_array('devices', $modules, true)) {
            $groups[] = ['label' => 'devices', 'tag' => DevicesModule::PUBLISH_TAG, 'suffixes' => self::suffixes([DevicesModule::migrationPath()])];
        }
        if ($profile === 'durable' || in_array('receipts', $modules, true)) {
            $groups[] = ['label' => 'durable ledger'.(in_array('receipts', $modules, true) ? ' and receipts' : ''), 'tag' => LedgerModule::PUBLISH_TAG, 'suffixes' => self::suffixes(LedgerModule::migrationPaths())];
        }
        if (in_array('live-activities', $modules, true)) {
            $groups[] = ['label' => 'live activities', 'tag' => LiveActivitiesModule::PUBLISH_TAG, 'suffixes' => self::suffixes(LiveActivitiesModule::migrationPaths())];
        }
        if (in_array('logs', $modules, true)) {
            $groups[] = ['label' => 'database log sink', 'tag' => LoggingModule::PUBLISH_TAG, 'suffixes' => self::suffixes([LoggingModule::migrationPath()])];
        }
        if (in_array('campaigns', $modules, true) && self::campaignsTag() !== null) {
            $groups[] = ['label' => 'campaigns', 'tag' => (string) self::campaignsTag(), 'suffixes' => []];
        }

        return $groups;
    }

    /** Whether the campaigns module exists in this version of the package. */
    public static function campaignsAvailable(): bool
    {
        return self::campaignsTag() !== null;
    }

    private static function campaignsTag(): ?string
    {
        $constant = self::CAMPAIGNS_MODULE.'::PUBLISH_TAG';

        if (class_exists(self::CAMPAIGNS_MODULE) && defined($constant)) {
            $tag = constant($constant);

            return is_string($tag) ? $tag : null;
        }

        return null;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string> file names without the date prefix, as published (the published copy gets a new prefix)
     */
    private static function suffixes(array $paths): array
    {
        return array_map(static fn (string $path): string => substr(basename($path), 18), $paths);
    }
}
