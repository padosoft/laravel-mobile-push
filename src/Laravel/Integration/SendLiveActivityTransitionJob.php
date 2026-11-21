<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Integration;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityManager;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityTicket;
use Padosoft\MobilePush\Laravel\LiveActivities\TransitionResult;

/**
 * Applies one Live Activity transition (update / end / broadcast publication) captured at dispatch time.
 *
 * Carries ActivityTicket::toArray(): the row id, the ticket number handed out at dispatch, the version seen then
 * and the payload (content state; no token: the token is read from the row when the job runs). Order is the order of
 * DISPATCH, not of execution: a ticket older than one already applied or pending is Skipped and sends nothing, and
 * nothing is sent after an end or the local expiry (ActivityManager::replay()). Never retried by the queue: a lost
 * response is the module's own replaceable-retry concern, a crash leaves at most the pending transition, which
 * the scheduled flushDue() sends.
 *
 * Dispatch: `dispatch(SendLiveActivityTransitionJob::for($manager->dispatchTicket($row, $payload)))`.
 */
final class SendLiveActivityTransitionJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $maxExceptions = 1;

    /** @param  array{target: string, id: string, seq: int, version: int, payload: array<string, mixed>}  $ticket */
    public function __construct(public readonly array $ticket) {}

    public static function for(ActivityTicket $ticket): self
    {
        return new self($ticket->toArray());
    }

    public function handle(ActivityManager $activities): TransitionResult
    {
        return $activities->replay(ActivityTicket::fromArray($this->ticket));
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['mobile-push', 'live-activity', $this->ticket['target'].':'.$this->ticket['id']];
    }

    public function displayName(): string
    {
        return sprintf('mobile-push live activity %s %s (ticket %d)', $this->ticket['target'], $this->ticket['id'], $this->ticket['seq']);
    }
}
