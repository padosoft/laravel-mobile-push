<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Pulse\Livewire;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;
use Padosoft\MobilePush\Laravel\Pulse\MobilePushRecorder;
use Padosoft\MobilePush\Laravel\Pulse\PulseSettings;

/**
 * The "Mobile push" Pulse card. Loaded only when Pulse is installed (it extends a Pulse class), registered by
 * PulseModule under the Livewire name `mobile-push.pulse-card`:
 *
 *     <livewire:mobile-push.pulse-card cols="6" />
 *
 * Reads only the aggregates written by MobilePushRecorder; there is nothing sensitive in them.
 *
 * @internal
 */
#[Lazy]
final class MobilePushCard extends Card
{
    private const FAILED = ['failed', 'expired'];

    /** Columns the card shows, in order. */
    private const COLUMNS = ['accepted', 'rejected', 'failed', 'unknown', 'deferred', 'skipped'];

    public function render(): Renderable
    {
        [$data, $time, $runAt] = $this->remember(fn (): array => $this->collect(), 'data');

        $rate = PulseSettings::fromConfig(app(Repository::class))->sampleRate;

        return View::make('mobile-push::pulse.card', [
            'connections' => $data['connections'],
            'reasons' => $data['reasons'],
            'openCircuits' => $data['open_circuits'],
            'throttled' => $data['throttled'],
            'throttleEvents' => $data['throttle_events'],
            'circuitTransitions' => $data['circuit_transitions'],
            'columns' => self::COLUMNS,
            'scale' => $rate > 0.0 ? 1 / $rate : 1.0,
            'sampleRate' => $rate,
            'time' => $time,
            'runAt' => $runAt,
        ]);
    }

    /**
     * @return array{connections: list<array<string, mixed>>, reasons: list<array{reason: string, count: int}>, open_circuits: list<string>, throttled: list<string>, throttle_events: int, circuit_transitions: int}
     */
    private function collect(): array
    {
        /** @var array<string, array<string, int|float>> $perConnection */
        $perConnection = [];

        foreach ($this->aggregate(MobilePushRecorder::OUTCOME, 'sum', limit: 2000) as $row) {
            $parts = $this->parts($row->key);
            if (count($parts) !== 4) {
                continue;
            }
            [$provider, $connection, , $status] = $parts;
            $label = $provider.' / '.$connection;
            $column = match ($status) {
                'accepted' => 'accepted',
                'rejected' => 'rejected',
                'failed', 'expired' => 'failed',
                'outcome_unknown' => 'unknown',
                'deferred' => 'deferred',
                'skipped' => 'skipped',
                default => null,
            };
            if ($column === null) {
                continue;
            }
            $perConnection[$label][$column] = ($perConnection[$label][$column] ?? 0) + (int) $row->sum;
        }

        foreach ($this->aggregate(MobilePushRecorder::BATCH, ['max', 'avg'], limit: 500) as $row) {
            $parts = $this->parts($row->key);
            if (count($parts) !== 2) {
                continue;
            }
            $label = $parts[0].' / '.$parts[1];
            $perConnection[$label]['max_ms'] = max((int) ($perConnection[$label]['max_ms'] ?? 0), (int) $row->max);
            $perConnection[$label]['avg_ms'] = (int) round((float) $row->avg);
        }

        ksort($perConnection);
        $connections = [];
        foreach ($perConnection as $label => $values) {
            $counted = ($values['accepted'] ?? 0) + ($values['rejected'] ?? 0) + ($values['failed'] ?? 0) + ($values['unknown'] ?? 0);
            $connections[] = [
                'label' => $label,
                'accepted' => (int) ($values['accepted'] ?? 0),
                'rejected' => (int) ($values['rejected'] ?? 0),
                'failed' => (int) ($values['failed'] ?? 0),
                'unknown' => (int) ($values['unknown'] ?? 0),
                'deferred' => (int) ($values['deferred'] ?? 0),
                'skipped' => (int) ($values['skipped'] ?? 0),
                'rate' => $counted > 0 ? round(($values['accepted'] ?? 0) / $counted * 100, 2) : null,
                'max_ms' => isset($values['max_ms']) ? (int) $values['max_ms'] : null,
                'avg_ms' => isset($values['avg_ms']) ? (int) $values['avg_ms'] : null,
            ];
        }

        $reasonTotals = [];
        foreach ($this->aggregate(MobilePushRecorder::REASON, 'count', limit: 1000) as $row) {
            $parts = $this->parts($row->key);
            if (count($parts) !== 3) {
                continue;
            }
            $reasonTotals[$parts[2]] = ($reasonTotals[$parts[2]] ?? 0) + (int) $row->count;
        }
        arsort($reasonTotals);
        $reasons = [];
        foreach (array_slice($reasonTotals, 0, 10, true) as $reason => $count) {
            $reasons[] = ['reason' => (string) $reason, 'count' => $count];
        }

        $now = time();
        $openCircuits = [];
        foreach ($this->values(MobilePushRecorder::CIRCUIT_STATE) as $key => $value) {
            $parts = $this->parts((string) $key);
            if (count($parts) === 3 && $value->value === 'open') {
                $openCircuits[] = $parts[0].' / '.$parts[1].' ('.str_replace('_', ' ', $parts[2]).')';
            }
        }
        $throttled = [];
        foreach ($this->values(MobilePushRecorder::THROTTLE_STATE) as $key => $value) {
            $parts = $this->parts((string) $key);
            if (count($parts) === 2 && (int) $value->value > $now) {
                $throttled[] = $parts[0].' / '.$parts[1];
            }
        }
        sort($openCircuits);
        sort($throttled);

        return [
            'connections' => $connections,
            'reasons' => $reasons,
            'open_circuits' => $openCircuits,
            'throttled' => $throttled,
            'throttle_events' => $this->total(MobilePushRecorder::THROTTLE),
            'circuit_transitions' => $this->total(MobilePushRecorder::CIRCUIT),
        ];
    }

    private function total(string $type): int
    {
        $total = $this->aggregateTotal($type, 'count');

        return is_numeric($total) ? (int) $total : 0;
    }

    /** @return list<string> */
    private function parts(string $key): array
    {
        $decoded = json_decode($key, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $part): string => is_scalar($part) ? (string) $part : '', $decoded));
    }
}
