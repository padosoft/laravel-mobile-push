<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Console;

use Padosoft\MobilePush\Core\Apns\Channels\ChannelApiException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Laravel\LiveActivities\BroadcastChannel;
use Padosoft\MobilePush\Laravel\LiveActivities\ChannelState;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\Input;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\InvalidInputException;
use Padosoft\MobilePush\Laravel\LiveActivities\SyncReport;

final class ChannelsSyncCommand extends ChannelCommand
{
    protected $signature = 'mobile-push:channels:sync
        {--connection= : The push connection (default: mobile-push.live_activities.binding)}
        {--scope= : The scope (default: the current one)}
        {--adopt= : Attach an orphan channel id to the local row whose creation was lost: CHANNEL_ID or CHANNEL_ID:LOCAL_ROW_ID}
        {--cleanup : Delete channels that no open activity references (asks for confirmation)}
        {--idle-hours= : With --cleanup: only channels idle at least this many hours (default: mobile-push.live_activities.channels.cleanup_after_hours)}
        {--force : With --cleanup: do not ask for confirmation (for the scheduler)}
        {--json : Print the report as JSON}';

    protected $description = 'Reconcile the local broadcast channels with the list APNs holds, in both directions';

    protected function perform(): int
    {
        $idle = $this->optionString('idle-hours') === null ? null : Input::positiveInt('idle-hours', $this->option('idle-hours'), 0, 87_600);
        $adopt = $this->optionString('adopt');
        if ($idle !== null && ! (bool) $this->option('cleanup')) {
            throw new InvalidInputException('--idle-hours only applies together with --cleanup.');
        }

        $binding = $this->binding();
        $decision = $this->safeMode()->decide($binding, OperationKind::ChannelManagement);
        if (! $decision->allowed) {
            $exit = $this->reportDecision($decision, sprintf('reconcile the broadcast channels of %s (%s) with APNs', $binding->appId, $binding->environment->value));
            if ($decision->isBlocked() && ! $this->wantsJson()) {
                $this->line('Remedy: '.self::MANAGEMENT_REMEDY);
            }

            return $exit;
        }

        $channels = $this->manager()->channels();
        $report = $channels->sync($binding);
        $adoptedNow = [];
        $adoptError = null;

        if ($adopt !== null) {
            [$adoptedNow, $adoptError] = $this->adopt($binding, $report, $adopt);
            if ($adoptedNow !== []) {
                $report = new SyncReport([...$report->adopted, ...$adoptedNow], $report->deletedLocally, $report->neverCreated, array_values(array_diff($report->orphans, $adoptedNow)), $report->remoteCount);
            }
        }

        $unresolved = $this->unresolvedRows($binding);
        $cleaned = [];
        $cleanupSkipped = null;
        if ((bool) $this->option('cleanup')) {
            [$cleaned, $cleanupSkipped] = $this->cleanup($binding, $idle);
        }

        $consistent = $report->orphans === [] && $adoptError === null;
        $cap = $this->capOf($binding);

        if ($this->wantsJson()) {
            $this->json([
                'ok' => $consistent,
                'remote_count' => $report->remoteCount,
                'adopted' => $report->adopted,
                'marked_deleted' => $report->deletedLocally,
                'never_created' => $report->neverCreated,
                'orphans' => $report->orphans,
                'unresolved_local_rows' => array_map(static fn (BroadcastChannel $r): string => $r->id, $unresolved),
                'cleanup_deleted' => $cleaned,
                'cleanup_skipped' => $cleanupSkipped,
                'adopt_error' => $adoptError,
                'cap' => $cap,
            ]);

            return $consistent ? self::EXIT_OK : self::EXIT_FAILED;
        }

        $this->line(sprintf('APNs lists %d channel(s) for %s (%s).', $report->remoteCount, $binding->appId, $binding->environment->value));
        $this->line('Adopted (attached to a local row whose creation was lost): '.($report->adopted === [] ? 'none' : implode(', ', $report->adopted)));
        $this->line('Marked deleted locally (APNs no longer lists them): '.($report->deletedLocally === [] ? 'none' : implode(', ', $report->deletedLocally)));
        $this->line('Rows resolved as never created (no orphan at APNs): '.$report->neverCreated);

        if ($report->orphans !== []) {
            $this->warn(sprintf('%d channel(s) exist at APNs that no local row claims and that could not be adopted safely:', count($report->orphans)));
            foreach ($report->orphans as $orphan) {
                $this->line('  orphan: '.$orphan);
            }
            if ($unresolved !== []) {
                $this->line(sprintf('%d local row(s) have a lost creation outcome: %s.', count($unresolved), implode(', ', array_map(static fn (BroadcastChannel $r): string => $r->id, $unresolved))));
                $this->line('Pick the orphan that belongs to the row and run: php artisan mobile-push:channels:sync --adopt=<channel-id>'.(count($unresolved) > 1 ? ':<local-row-id>' : ''));
            } else {
                $this->line('No local row is waiting for one, so they may belong to another environment of the system or to another tool. They are left alone; this package does not delete channels it did not create.');
            }
        }
        if ($adoptError !== null) {
            $this->error($adoptError);
        }
        if ($cleanupSkipped !== null) {
            $this->line($cleanupSkipped);
        }
        foreach ($cleaned as $id) {
            $this->line('Deleted unreferenced channel: '.$id);
        }
        $this->line($this->capLine($binding));

        return $consistent ? self::EXIT_OK : self::EXIT_FAILED;
    }

    /** @return array{0: list<string>, 1: string|null} adopted ids, error */
    private function adopt(BindingRef $binding, SyncReport $report, string $adopt): array
    {
        [$channelId, $rowId] = array_pad(explode(':', $adopt, 2), 2, null);

        if (! in_array($channelId, $report->orphans, true)) {
            return [[], '--adopt: "'.$channelId.'" is not an orphan APNs reported just now (orphans: '.($report->orphans === [] ? 'none' : implode(', ', $report->orphans)).').'];
        }

        $rows = $this->unresolvedRows($binding);
        if ($rowId !== null) {
            $rows = array_values(array_filter($rows, static fn (BroadcastChannel $r): bool => $r->id === $rowId));
        }
        if (count($rows) !== 1) {
            return [[], count($rows) === 0
                ? '--adopt: no local row of this connection is waiting for a channel id'.($rowId === null ? '.' : ' with the id "'.$rowId.'".')
                : '--adopt: '.count($rows).' local rows are waiting ('.implode(', ', array_map(static fn (BroadcastChannel $r): string => $r->id, $rows)).'): say which one with --adopt='.$channelId.':<local-row-id>.'];
        }

        $this->manager()->channels()->adopt($rows[0], (string) $channelId);

        return [[(string) $channelId], null];
    }

    /** @return list<BroadcastChannel> */
    private function unresolvedRows(BindingRef $binding): array
    {
        $out = [];
        foreach (BroadcastChannel::blank()->newQuery()->forBinding($binding)->whereNull('channel_id')
            ->whereIn('state', [ChannelState::Unknown->value, ChannelState::Creating->value])->orderBy('created_at')->get() as $row) {
            $out[] = $row;
        }

        return $out;
    }

    /** @return array{0: list<string>, 1: string|null} deleted channel ids, why nothing was deleted */
    private function cleanup(BindingRef $binding, ?int $idle): array
    {
        $channels = $this->manager()->channels();
        $candidates = $channels->unreferenced($binding, $idle);

        if ($candidates === []) {
            return [[], 'Cleanup: no channel is unreferenced and idle.'];
        }

        if (! (bool) $this->option('force') && ! $this->confirm(sprintf('Delete %d channel(s) with no open activity (%s)? This is irreversible.', count($candidates), implode(', ', array_map(static fn (BroadcastChannel $c): string => (string) $c->channel_id, $candidates))), false)) {
            return [[], 'Cleanup: aborted, nothing was deleted.'];
        }

        $deleted = [];
        foreach ($candidates as $candidate) {
            try {
                if ($channels->delete($candidate)->state === ChannelState::Deleted) {
                    $deleted[] = (string) $candidate->channel_id;
                }
            } catch (ChannelApiException) {
                continue;
            }
        }

        return [$deleted, null];
    }
}
