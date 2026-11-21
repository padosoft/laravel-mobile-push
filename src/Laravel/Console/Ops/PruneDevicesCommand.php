<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Ops;

use Padosoft\MobilePush\Laravel\Devices\DevicePruner;

/**
 * Deletes dead device registrations: invalidated ones after a grace period, and any registration not seen for a long
 * time. Schedulable; a disabled devices module is a notice with exit code 0.
 *
 * Config: mobile-push.devices.prune.invalidated_days (default 30), mobile-push.devices.prune.inactive_days (default 365).
 */
final class PruneDevicesCommand extends OpsCommand
{
    public const DEFAULT_INVALIDATED_DAYS = 30;

    public const DEFAULT_INACTIVE_DAYS = 365;

    protected $signature = 'mobile-push:devices:prune
        {--invalidated-days= : Delete invalidated registrations this many days after the change (default: devices.prune.invalidated_days, 30)}
        {--inactive-days= : Delete registrations not seen for this many days (default: devices.prune.inactive_days, 365)}
        {--pretend : Count what would be deleted without deleting}
        {--json : Print the summary as JSON}';

    protected $description = 'Delete invalidated and long-inactive device registrations';

    public function handle(): int
    {
        if (! (bool) config('mobile-push.devices.enabled', false)) {
            $this->components->warn('Skipped: the devices module is disabled (MOBILE_PUSH_DEVICES_ENABLED=true enables it).');

            return self::SUCCESS;
        }

        $invalidated = $this->intOption('invalidated-days') ?? self::configured('invalidated_days', self::DEFAULT_INVALIDATED_DAYS);
        $inactive = $this->intOption('inactive-days') ?? self::configured('inactive_days', self::DEFAULT_INACTIVE_DAYS);
        $pretend = (bool) $this->option('pretend');

        $report = $this->laravel->make(DevicePruner::class)->prune($invalidated, $inactive, $pretend);

        $this->emitSummary($pretend ? 'Pretend: nothing was deleted' : 'Prune finished', [
            'invalidated_days' => $invalidated,
            'inactive_days' => $inactive,
            'pretend' => $pretend,
            ($pretend ? 'would_delete' : 'deleted') => ['invalidated' => $report->invalidated, 'inactive' => $report->inactive, 'total' => $report->total()],
        ]);

        return self::SUCCESS;
    }

    private static function configured(string $key, int $default): ?int
    {
        $value = config('mobile-push.devices.prune.'.$key, $default);

        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }
}
