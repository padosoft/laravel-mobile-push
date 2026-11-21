<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Telescope;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Laravel\Sending\Events\PushBatchCompleted;
use Padosoft\MobilePush\Laravel\Sending\Events\PushResultProduced;

/**
 * Records push activity in Telescope.
 *
 * Entry type: Telescope has a fixed list of entry types (Laravel\Telescope\EntryType) and the UI has one screen per
 * type, with the routes compiled into its JavaScript bundle. A custom type would be stored but never listed. The
 * entries are therefore recorded as `event` entries (Telescope::recordEvent): they show in the Events screen with the
 * name `mobile-push.batch` / `mobile-push.result`, the tags below, and the payload as JSON.
 *
 * Per result only the ones that need attention are recorded (rejected, failed, deferred, unknown, expired, skipped);
 * an accepted result is counted by its batch entry. Simulated and validated results are not recorded.
 *
 * Never recorded: device tokens, bearers, receipt markers, payloads, the provider's raw reason text. A destination is
 * its kind and masked form only.
 *
 * Observability only: any failure is swallowed and counted.
 */
final class PushWatcher
{
    public const BATCH = 'mobile-push.batch';

    public const RESULT = 'mobile-push.result';

    private const EVENTS_NAMESPACE = 'Padosoft\\MobilePush\\Laravel\\Sending\\Events\\';

    private const RECORDED = [
        SendStatus::Rejected,
        SendStatus::Failed,
        SendStatus::Deferred,
        SendStatus::OutcomeUnknown,
        SendStatus::Expired,
        SendStatus::Skipped,
    ];

    private static bool $hidden = false;

    private int $failures = 0;

    public function __construct(private readonly Repository $config) {}

    public function register(Dispatcher $events): void
    {
        $events->listen(PushBatchCompleted::class, fn (PushBatchCompleted $event) => $this->batch($event));
        $events->listen(PushResultProduced::class, fn (PushResultProduced $event) => $this->result($event));
    }

    public function failures(): int
    {
        return $this->failures;
    }

    /** Keep Telescope's generic event watcher from serialising the package's raw events. Registered once. */
    public static function hideRawEvents(): void
    {
        if (self::$hidden) {
            return;
        }
        self::$hidden = true;

        Telescope::filter(static function (IncomingEntry $entry): bool {
            if ($entry->type !== EntryType::EVENT) {
                return true;
            }
            $name = $entry->content['name'] ?? null;

            return ! (is_string($name) && str_starts_with($name, self::EVENTS_NAMESPACE));
        });
    }

    public function batch(PushBatchCompleted $event): void
    {
        try {
            if (! $this->wanted() || ! Telescope::isRecording()) {
                return;
            }
            $binding = $event->binding;
            $counts = array_filter($event->counts, static fn (int $n): bool => $n > 0);

            $tags = ['mobile-push', 'push:'.self::label($binding->name), 'push-provider:'.$binding->provider->value];
            foreach (array_keys($counts) as $status) {
                $tags[] = 'push-status:'.self::label((string) $status);
            }

            Telescope::recordEvent(IncomingEntry::make([
                'name' => self::BATCH,
                'payload' => [
                    'connection' => self::label($binding->name),
                    'provider' => $binding->provider->value,
                    'environment' => $binding->environment->value,
                    'traffic_class' => $event->trafficClass->value,
                    'total' => $event->total,
                    'counts' => $counts,
                    'duration_ms' => round($event->durationMs, 2),
                ],
                'listeners' => [],
                'broadcast' => false,
            ])->tags($tags));
        } catch (\Throwable) {
            $this->failures++;
        }
    }

    public function result(PushResultProduced $event): void
    {
        try {
            $result = $event->result;
            if (! $this->wanted() || ! Telescope::isRecording() || ! in_array($result->status, self::RECORDED, true)) {
                return;
            }
            $binding = $event->binding;
            $reason = $result->reason;

            Telescope::recordEvent(IncomingEntry::make([
                'name' => self::RESULT,
                'payload' => [
                    'connection' => self::label($binding->name),
                    'provider' => $binding->provider->value,
                    'environment' => $binding->environment->value,
                    'traffic_class' => $event->trafficClass->value,
                    'status' => $result->status->value,
                    'reason_code' => $reason->code->value,
                    'hint' => $reason->hint === null ? null : self::text($reason->hint),
                    'http_status' => $result->httpStatus,
                    'attempts' => $result->attempts,
                    'provider_message_id' => $result->providerMessageId,
                    'delivery_id' => $result->deliveryId,
                    'next_attempt_at' => $result->nextAttemptAt?->format(DATE_ATOM),
                    'destination' => [
                        'kind' => self::label($result->destination->kind),
                        'masked' => self::text($result->destination->masked, 64),
                    ],
                ],
                'listeners' => [],
                'broadcast' => false,
            ])->tags([
                'mobile-push',
                'push:'.self::label($binding->name),
                'push-provider:'.$binding->provider->value,
                'push-status:'.$result->status->value,
                'push-reason:'.$reason->code->value,
            ]));
        } catch (\Throwable) {
            $this->failures++;
        }
    }

    /** The flag is read live, like the Pulse recorder does, so turning it off takes effect without a reboot. */
    private function wanted(): bool
    {
        return TelescopeModule::wanted($this->config);
    }

    private static function label(string $value): string
    {
        $clean = (string) preg_replace('/[^A-Za-z0-9._:-]+/', '_', $value);

        return $clean === '' ? 'unknown' : substr($clean, 0, 64);
    }

    private static function text(string $value, int $max = 300): string
    {
        return mb_substr((new Redactor)->providerMessage($value), 0, $max);
    }
}
