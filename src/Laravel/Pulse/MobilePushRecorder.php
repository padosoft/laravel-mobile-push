<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Pulse;

use Illuminate\Contracts\Config\Repository;
use Laravel\Pulse\Pulse;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Sending\Events\CircuitClosed;
use Padosoft\MobilePush\Laravel\Sending\Events\CircuitOpened;
use Padosoft\MobilePush\Laravel\Sending\Events\CredentialFailedOver;
use Padosoft\MobilePush\Laravel\Sending\Events\ProviderThrottled;
use Padosoft\MobilePush\Laravel\Sending\Events\PushBatchCompleted;
use Padosoft\MobilePush\Laravel\Sending\Events\PushResultProduced;

/**
 * Pulse recorder for the push events.
 *
 * Pulse has no recorder interface: a recorder is any class whose `$listen` names the events it wants and whose
 * `record($event)` handles them (see Laravel\Pulse\Pulse::register()). Registered by PulseModule.
 *
 * Pulse types written (every key is a JSON list of low-cardinality parts, never a device, user, campaign or message):
 *  - mobile_push_outcome  [provider, connection, environment, status]  sum of the batch counters
 *  - mobile_push_reason   [provider, connection, reason_code]           count, non-accepted results only
 *  - mobile_push_batch    [provider, connection]                        duration in ms, max and avg
 *  - mobile_push_throttle [provider, connection]                        count of throttle events
 *  - mobile_push_circuit  [provider, connection, guard, transition]     count of transitions
 *  - mobile_push_circuit_state  (value) [provider, connection, guard] => open|closed
 *  - mobile_push_throttle_state (value) [provider, connection] => unix time until which the provider is throttled
 *
 * Status counters come from PushBatchCompleted, which is dispatched for every batch whatever the traffic class.
 * Reason codes come from PushResultProduced, which exists only for the traffic `events.per_result` selects (by
 * default transactional): for complete reason coverage of bulk traffic set it to "always".
 *
 * Observability only: any failure is swallowed and counted.
 */
final class MobilePushRecorder
{
    public const OUTCOME = 'mobile_push_outcome';

    public const REASON = 'mobile_push_reason';

    public const BATCH = 'mobile_push_batch';

    public const THROTTLE = 'mobile_push_throttle';

    public const CIRCUIT = 'mobile_push_circuit';

    public const CIRCUIT_STATE = 'mobile_push_circuit_state';

    public const THROTTLE_STATE = 'mobile_push_throttle_state';

    /** @var list<class-string> */
    public array $listen = [
        PushBatchCompleted::class,
        PushResultProduced::class,
        ProviderThrottled::class,
        CircuitOpened::class,
        CircuitClosed::class,
        CredentialFailedOver::class,
    ];

    private int $failures = 0;

    /** @var (callable(): float)|null */
    private $roller;

    private KeyNormalizer $keys;

    private ?int $keysMax = null;

    /** @param  (callable(): float)|null  $roller  returns a number in [0, 1); injectable for tests */
    public function __construct(
        private readonly Pulse $pulse,
        private readonly Repository $config,
        ?callable $roller = null,
    ) {
        $this->roller = $roller;
        $this->keys = new KeyNormalizer;
    }

    public function failures(): int
    {
        return $this->failures;
    }

    public function record(object $event): void
    {
        try {
            $settings = PulseSettings::fromConfig($this->config);
            if (! $settings->enabled) {
                return;
            }
            if ($this->keysMax !== $settings->maxConnections) {
                $this->keys = new KeyNormalizer($settings->maxConnections);
                $this->keysMax = $settings->maxConnections;
            }

            match (true) {
                $event instanceof PushBatchCompleted => $this->batch($event, $settings),
                $event instanceof PushResultProduced => $this->result($event, $settings),
                $event instanceof ProviderThrottled => $this->throttled($event),
                $event instanceof CircuitOpened => $this->circuit($event->binding, $event->guard->value, 'open', $event->transition->value),
                $event instanceof CircuitClosed => $this->circuit($event->binding, $event->guard->value, 'closed', $event->transition->value),
                $event instanceof CredentialFailedOver => $this->circuit($event->binding, $event->guard->value, null, $event->transition->value),
                default => null,
            };
        } catch (\Throwable) {
            $this->failures++;
        }
    }

    private function sampled(PulseSettings $settings): bool
    {
        return Sampler::keeps($settings->sampleRate, $this->roller !== null ? ($this->roller)() : Sampler::roll());
    }

    private function batch(PushBatchCompleted $event, PulseSettings $settings): void
    {
        if (! $this->sampled($settings)) {
            return;
        }
        $provider = $event->binding->provider->value;
        $connection = $this->keys->connection($event->binding->name);
        $environment = $event->binding->environment->value;

        foreach ($event->counts as $status => $count) {
            if (! is_int($count) || $count <= 0 || SendStatus::tryFrom((string) $status) === null) {
                continue;
            }
            $this->pulse->record(self::OUTCOME, KeyNormalizer::key([$provider, $connection, $environment, (string) $status]), $count)->sum();
        }

        $this->pulse->record(self::BATCH, KeyNormalizer::key([$provider, $connection]), max(0, (int) round($event->durationMs)))->max()->avg();
    }

    private function result(PushResultProduced $event, PulseSettings $settings): void
    {
        $status = $event->result->status;
        if ($status->isAccepted() || ! $this->sampled($settings)) {
            return;
        }
        $this->pulse->record(self::REASON, KeyNormalizer::key([
            $event->binding->provider->value,
            $this->keys->connection($event->binding->name),
            $event->result->reason->code->value,
        ]))->count();
    }

    private function throttled(ProviderThrottled $event): void
    {
        $provider = $event->binding->provider->value;
        $connection = $this->keys->connection($event->binding->name);
        $key = KeyNormalizer::key([$provider, $connection]);

        $this->pulse->record(self::THROTTLE, $key)->count();
        $this->pulse->set(self::THROTTLE_STATE, $key, (string) $event->until->getTimestamp());
    }

    private function circuit(BindingRef $binding, string $guard, ?string $state, string $transition): void
    {
        $provider = $binding->provider->value;
        $connection = $this->keys->connection($binding->name);

        $this->pulse->record(self::CIRCUIT, KeyNormalizer::key([$provider, $connection, $guard, $transition]))->count();
        if ($state !== null) {
            $this->pulse->set(self::CIRCUIT_STATE, KeyNormalizer::key([$provider, $connection, $guard]), $state);
        }
    }
}
