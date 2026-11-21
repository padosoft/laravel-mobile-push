<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Console\Support;

use Padosoft\MobilePush\Core\Ledger\DeliveryState;
use Padosoft\MobilePush\Laravel\Campaigns\Campaign;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignProgress;
use Padosoft\MobilePush\Laravel\Campaigns\ThroughputEstimate;

/**
 * How a CampaignProgress is shown: text lines for people, an array for --json. Pure (no I/O), so it is unit tested.
 * No destination is ever shown: the progress holds none.
 */
final class StatusView
{
    /** @return array<string, int|string|float|bool|null> */
    public static function summary(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'scope' => $campaign->scope,
            'status' => $campaign->status->value,
            'connection' => $campaign->binding->name,
            'audience' => $campaign->audienceType,
            'reach' => $campaign->reach->value,
            'planned' => $campaign->planned,
            'created_at' => self::iso($campaign->createdAt),
            'started_at' => self::iso($campaign->startedAt),
            'finished_at' => self::iso($campaign->finishedAt),
        ];
    }

    /** @return array<string, mixed> */
    public static function data(CampaignProgress $p): array
    {
        $estimate = $p->estimate;

        return [
            'campaign' => self::summary($p->campaign),
            'planning_finished' => $p->planningFinished(),
            'planned' => $p->campaign->planned,
            'total' => $p->total(),
            'percent' => $p->percent(),
            'recipients_known' => $p->recipientsKnown,
            'counts' => [
                'accepted' => $p->accepted,
                'rejected' => $p->rejected,
                'failed' => $p->failed,
                'expired' => $p->expired,
                'unknown_outcome' => $p->unknown,
                'deferred' => $p->counts->of(DeliveryState::Deferred),
                'pending' => $p->counts->of(DeliveryState::Pending),
                'in_flight' => $p->inFlight,
                'skipped' => $p->skipped,
                'cancelled' => $p->cancelled,
                'simulated' => $p->simulated,
                'validated' => $p->validated,
                'terminal' => $p->terminal,
            ],
            'by_state' => $p->byState(),
            'estimate' => $estimate === null ? null : [
                'is_estimate' => true,
                'per_second' => $estimate->perSecond,
                'remaining_seconds' => $estimate->remainingSeconds,
                'basis' => $estimate->basis,
                'window_seconds' => $estimate->windowSeconds,
                'covers_whole_audience' => $estimate->coversWholeAudience,
            ],
            'receipts' => $p->funnel?->toArray(),
            'notes' => $p->notes,
        ];
    }

    /** @return list<string> */
    public static function lines(CampaignProgress $p): array
    {
        $c = $p->campaign;
        $n = static fn (int $v): string => number_format($v);
        $lines = [];
        $lines[] = sprintf('Campaign %s (%s)  status: %s', $c->name, $c->id, strtoupper($c->status->value));
        $lines[] = sprintf('Connection: %s   scope: %s   audience: %s', $c->binding->name, $c->scope, $c->audienceType);
        $percent = $p->percent();
        $lines[] = sprintf(
            'Planned: %s destinations%s',
            $n($p->total()),
            $p->planningFinished() ? ' (planning finished)' : ' so far (planning is not finished: this is not the whole audience)',
        );
        $lines[] = 'Done: '.($percent === null ? 'nothing planned yet' : rtrim(rtrim(number_format($percent, 2), '0'), '.').'% of what is planned');
        $lines[] = '';
        $lines[] = sprintf('  Accepted         %10s   the provider accepted the request (not proof that a phone showed it)', $n($p->accepted));
        $lines[] = sprintf('  Rejected         %10s   the provider refused it', $n($p->rejected));
        $lines[] = sprintf('  Failed           %10s   known not accepted, no attempt left', $n($p->failed + $p->expired));
        $lines[] = sprintf('  UNKNOWN OUTCOME  %10s   the response was lost: it may have been accepted; never counted as failed', $n($p->unknown));
        $lines[] = sprintf('  Deferred         %10s   waiting for a later attempt', $n($p->counts->of(DeliveryState::Deferred)));
        $lines[] = sprintf('  Pending          %10s   waiting for a worker (in flight now: %s)', $n($p->counts->of(DeliveryState::Pending)), $n($p->inFlight));
        $lines[] = sprintf('  Skipped          %10s   not sent (consent, gates, expiry...)', $n($p->skipped));
        $lines[] = sprintf('  Cancelled        %10s   cancelled before being sent', $n($p->cancelled));
        if ($p->simulated + $p->validated > 0) {
            $lines[] = sprintf('  Simulated/valid. %10s   nothing was sent', $n($p->simulated + $p->validated));
        }
        $lines[] = '';
        $lines[] = self::estimateLine($p->estimate);

        if (! $p->recipientsKnown) {
            $lines[] = 'Recipients are not known: this audience is a topic, condition or channel, so one delivery is one publication, not one person.';
        } elseif ($p->funnel === null) {
            $lines[] = 'Receipts: not available (client receipts are not enabled).';
        } else {
            $f = $p->funnel;
            $lines[] = sprintf(
                'Receipts (reported by the app, not proof): %s accepted, %s able to report, %s reported received, %s reported opened. A missing report is "not observed", not "not delivered".',
                $n($f->accepted), $n($f->acceptedWithMarker), $n($f->reportedReceived), $n($f->reportedOpened),
            );
        }
        foreach ($p->notes as $note) {
            $lines[] = 'Note: '.$note;
        }
        if ($c->lastError !== null) {
            $lines[] = 'Last planner error: '.$c->lastError;
        }

        return $lines;
    }

    public static function estimateLine(?ThroughputEstimate $e): string
    {
        if ($e === null) {
            return 'Estimate: none yet (the campaign has not started).';
        }
        if ($e->remainingSeconds === null) {
            return 'Estimate (a guess, not a promise): no pace measured yet, so no time left can be given.';
        }

        return sprintf(
            'Estimate (a guess, not a promise): about %s left at %s deliveries/s, pace measured %s%s.',
            $e->remainingSeconds === 0 ? '0s' : self::duration($e->remainingSeconds),
            rtrim(rtrim(number_format($e->perSecond, 3), '0'), '.') ?: '0',
            $e->basis === ThroughputEstimate::BASIS_RECENT ? 'over the last '.$e->windowSeconds.'s' : 'since the start ('.$e->windowSeconds.'s)',
            $e->coversWholeAudience ? '' : '; destinations not yet planned are not included',
        );
    }

    public static function duration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return match (true) {
            $h > 0 => sprintf('%dh %dm', $h, $m),
            $m > 0 => sprintf('%dm %ds', $m, $s),
            default => $s.'s',
        };
    }

    private static function iso(?\DateTimeInterface $at): ?string
    {
        return $at?->format('Y-m-d\TH:i:sP');
    }
}
