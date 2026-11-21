<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Ops;

/**
 * The explicit environment probe (plan section 4.4): tries quarantined APNs device tokens on the OTHER APNs
 * environment with ONE silent background push each, and corrects the registration's environment label when Apple
 * accepts. A device is contacted at most once per `devices.probe.cooldown_hours` (default 24): `--force` does not bypass
 * that, `--ignore-cooldown` does after an interactive confirmation. See EnvironmentProber for the rules. It is the only operation that ever tries a token on the other
 * environment; a normal send never does.
 *
 * Exit code: 0 every device got a clean answer (relabelled, still rejected, simulated, or skipped by the cooldown), 1 something needs attention
 * (refused, no connection, untouched, conflict) or the run was refused, 2 the run was stopped by an authentication error.
 */
final class ProbeEnvironmentCommand extends OpsCommand
{
    public const DEFAULT_LIMIT = 10;

    public const MAX_LIMIT = 100;

    protected $signature = 'mobile-push:devices:probe-environment
        {--device= : Probe this single device (registry id); it must be quarantined}
        {--quarantined : Probe the quarantined APNs device tokens of the scope}
        {--connection= : Name of the connection of the same app on the other environment (when several match)}
        {--limit=10 : Devices to probe in one run (1-100): every rejected token counts against Apple\'s error budget}
        {--allow-cross-environment : Required: acknowledge that tokens are sent to the other APNs environment}
        {--scope= : Scope (tenant); default is the current scope}
        {--ignore-cooldown : Also probe devices probed less than devices.probe.cooldown_hours ago (always asks, --force does not skip it)}
        {--force : Do not ask for the usual confirmation (it does NOT bypass the cooldown: use --ignore-cooldown)}
        {--json : Print the result as JSON}';

    protected $description = 'Try quarantined APNs device tokens on the other APNs environment and correct their environment label';

    public function handle(EnvironmentProber $prober): int
    {
        if (! (bool) $this->option('allow-cross-environment')) {
            throw new UsageError('Refused: this command sends device tokens to the OTHER APNs environment than the one they were registered for. It is an explicit operator action; pass --allow-cross-environment to confirm that is what you want. Nothing was sent.');
        }
        $device = $this->stringOption('device');
        if ($device === null && ! (bool) $this->option('quarantined')) {
            throw new UsageError('Say what to probe: --quarantined (every quarantined APNs device token of the scope) or --device=<id>.');
        }
        if (! (bool) config('mobile-push.devices.enabled', false)) {
            throw new UsageError('The devices module is disabled (MOBILE_PUSH_DEVICES_ENABLED=true): there is no registry to probe.');
        }
        $limit = $this->intOption('limit', 1) ?? self::DEFAULT_LIMIT;
        if ($limit > self::MAX_LIMIT) {
            throw new UsageError('--limit cannot exceed '.self::MAX_LIMIT.': rejected tokens count against Apple\'s error budget. Run the command again for the next batch.');
        }

        $ignoreCooldown = (bool) $this->option('ignore-cooldown');
        if ($ignoreCooldown) {
            if (! $this->input->isInteractive()) {
                throw new UsageError('--ignore-cooldown needs an interactive confirmation (--force does not replace it): a device probed too often costs Apple error budget. Nothing was sent.');
            }
            if (! $this->confirm('Probe devices even when they were probed less than the cooldown ago? Every rejected probe counts against Apple\'s error budget.', false)) {
                $this->components->warn('Aborted: nothing was sent.');

                return self::FAILURE;
            }
        }

        $scope = $this->currentScope();
        $selection = $prober->select($scope, $device, $limit, $ignoreCooldown);
        $coolingRows = $prober->coolingRows($selection['cooling']);
        if ($selection['devices'] === []) {
            if ($coolingRows === []) {
                $this->emitOrSay(['rows' => [], 'stopped' => false], 'No quarantined APNs device token matches in scope "'.$scope.'": nothing to probe.');

                return self::SUCCESS;
            }
            $this->emitCooling($coolingRows, $selection['cooling_total']);

            return self::SUCCESS;
        }

        $items = $prober->plan($scope, $selection['devices'], $this->stringOption('connection'));
        $sendable = array_values(array_filter($items, static fn (ProbeItem $i): bool => $i->send));

        if ($sendable !== []) {
            if (! $this->option('force')) {
                if (! $this->input->isInteractive()) {
                    throw new UsageError('Refusing to send in a non-interactive run without --force. Nothing was sent.');
                }
                if (! $this->confirm(sprintf('Send one silent push to the other APNs environment for %d quarantined device(s)? Rejected tokens count against Apple\'s error budget.', count($sendable)), false)) {
                    $this->components->warn('Aborted: nothing was sent.');

                    return self::FAILURE;
                }
            }
            $run = $prober->run($items);
            $rows = $run['rows'];
            $stopped = $run['stopped'];
        } else {
            $rows = array_map(static fn (ProbeItem $i): ProbeRow => $i->row, $items);
            $stopped = false;
        }

        $rows = [...$rows, ...$coolingRows];
        $this->emitOrSay(['rows' => array_map(static fn (ProbeRow $r): array => $r->toArray(), $rows), 'stopped' => $stopped, 'not_probed_over_limit' => $selection['more'], 'cooling_down' => $selection['cooling_total']], null);

        if (! $this->wantsJson()) {
            $this->table(['Device', 'Token', 'App', 'From', 'To', 'Outcome', 'Detail'], array_map(
                static fn (ProbeRow $r): array => [$r->deviceId, $r->maskedToken, $r->appId, $r->from, $r->to ?? '-', $r->outcome->value, $r->detail],
                $rows,
            ));
            if ($selection['more'] > 0) {
                $this->components->warn($selection['more'].' more quarantined device(s) were not probed (limit '.$limit.'). Run the command again for the next batch.');
            }
            if ($selection['cooling_total'] > 0) {
                $this->components->warn($selection['cooling_total'].' device(s) were skipped because they were probed less than '.(string) config('mobile-push.devices.probe.cooldown_hours', 24).' hour(s) ago (--ignore-cooldown overrides, after a confirmation).');
            }
            if ($stopped) {
                $this->components->error('The run was stopped by an authentication error: the signing key may be limited to one environment. No further device was contacted.');
            }
        }

        if ($stopped) {
            return 2;
        }
        foreach ($rows as $row) {
            if ($row->outcome->needsAttention()) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /** @param  list<ProbeRow>  $rows */
    private function emitCooling(array $rows, int $total): void
    {
        if ($this->wantsJson()) {
            $this->emitJson(['rows' => array_map(static fn (ProbeRow $r): array => $r->toArray(), $rows), 'stopped' => false, 'not_probed_over_limit' => 0, 'cooling_down' => $total]);

            return;
        }
        $this->components->info($total.' quarantined device(s) were probed less than '.(string) config('mobile-push.devices.probe.cooldown_hours', 24).' hour(s) ago: nobody was contacted.');
        $this->table(['Device', 'Token', 'App', 'From', 'To', 'Outcome', 'Detail'], array_map(
            static fn (ProbeRow $r): array => [$r->deviceId, $r->maskedToken, $r->appId, $r->from, $r->to ?? '-', $r->outcome->value, $r->detail],
            $rows,
        ));
    }

    /** @param  array<string, mixed>  $data */
    private function emitOrSay(array $data, ?string $text): void
    {
        if ($this->wantsJson()) {
            $this->emitJson($data);
        } elseif ($text !== null) {
            $this->components->info($text);
        }
    }
}
