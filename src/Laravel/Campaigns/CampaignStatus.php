<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

/**
 * Lifecycle of a campaign.
 *
 *   draft ──start──▶ planning ──(last slice written)──▶ running ──(every delivery terminal)──▶ completed
 *                     │  ▲                                  │
 *                pause│  │resume                            │
 *                     ▼  │                                  │
 *                    paused ─(last slice already written)─▶ running
 *   planning ──(permanent planner error)──▶ failed ──resume──▶ planning
 *   draft | planning | paused | running | failed ──cancel──▶ cancelled
 *
 * planning: slices are being written. paused: no further slice is written (deliveries already written proceed).
 * running: every slice is written; deliveries are still waiting or in flight.
 * completed, cancelled: final. failed: planning stopped on an error; resumable from the stored cursor.
 */
enum CampaignStatus: string
{
    case Draft = 'draft';
    case Planning = 'planning';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    /**
     * The status after `$control`: the same status when the control is an idempotent no-op, null when the control is
     * not allowed from here.
     */
    public function after(CampaignControl $control): ?self
    {
        return match ($control) {
            CampaignControl::Start => match ($this) {
                self::Draft => self::Planning,
                self::Planning, self::Paused, self::Running => $this,
                default => null,
            },
            CampaignControl::Pause => match ($this) {
                self::Planning => self::Paused,
                self::Paused => $this,
                default => null,
            },
            CampaignControl::Resume => match ($this) {
                self::Paused, self::Failed => self::Planning,
                self::Planning, self::Running => $this,
                default => null,
            },
            CampaignControl::Cancel => match ($this) {
                self::Draft, self::Planning, self::Paused, self::Running, self::Failed => self::Cancelled,
                self::Cancelled => $this,
                default => null,
            },
            CampaignControl::FinishPlanning => match ($this) {
                self::Planning, self::Paused => self::Running,
                self::Running, self::Completed, self::Cancelled => $this,
                default => null,
            },
            CampaignControl::Complete => match ($this) {
                self::Running => self::Completed,
                self::Completed => $this,
                default => null,
            },
            CampaignControl::Fail => match ($this) {
                self::Planning => self::Failed,
                self::Failed => $this,
                default => null,
            },
        };
    }

    /** No further change can happen (except pruning). */
    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    /** The planner may write slices. */
    public function acquires(): bool
    {
        return $this === self::Planning;
    }

    /** @return list<string> */
    public static function values(self ...$statuses): array
    {
        return array_values(array_map(static fn (self $s): string => $s->value, $statuses));
    }
}
