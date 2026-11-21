<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Pulse\Livewire;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\View\Factory as ViewFactory;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;
use Padosoft\MobilePush\Laravel\Pulse\CardData;
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
    private const VIEW = 'mobile-push::pulse.card';

    /** Columns the card shows, in order. */
    private const COLUMNS = ['accepted', 'rejected', 'failed', 'unknown', 'deferred', 'skipped'];

    public function render(): Renderable
    {
        [$data, $time, $runAt] = $this->remember(fn (): CardData => $this->collect(), 'data');
        if (! $data instanceof CardData) {
            $data = new CardData;
        }

        $rate = PulseSettings::fromConfig(app(Repository::class))->sampleRate;

        // Resolved through the finder (so a published override wins) and rendered as a file: the view lives in the
        // package's own namespace, which static analysis cannot know as a view-string.
        $views = app(ViewFactory::class);

        return $views->file($views->getFinder()->find(self::VIEW), [
            'connections' => $data->connections,
            'reasons' => $data->reasons,
            'openCircuits' => $data->openCircuits,
            'throttled' => $data->throttled,
            'throttleEvents' => $data->throttleEvents,
            'circuitTransitions' => $data->circuitTransitions,
            'columns' => self::COLUMNS,
            'scale' => $rate > 0.0 ? 1 / $rate : 1.0,
            'sampleRate' => $rate,
            'time' => $time,
            'runAt' => $runAt,
        ]);
    }

    private function collect(): CardData
    {
        /** @var array<string, array<string, int>> $perConnection */
        $perConnection = [];

        foreach ($this->aggregate(MobilePushRecorder::OUTCOME, 'sum', limit: 2000) as $row) {
            $fields = self::fields($row);
            $parts = $this->parts($fields['key'] ?? null);
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
            $perConnection[$label][$column] = ($perConnection[$label][$column] ?? 0) + self::int($fields['sum'] ?? null);
        }

        foreach ($this->aggregate(MobilePushRecorder::BATCH, ['max', 'avg'], limit: 500) as $row) {
            $fields = self::fields($row);
            $parts = $this->parts($fields['key'] ?? null);
            if (count($parts) !== 2) {
                continue;
            }
            $label = $parts[0].' / '.$parts[1];
            $perConnection[$label]['max_ms'] = max($perConnection[$label]['max_ms'] ?? 0, self::int($fields['max'] ?? null));
            $perConnection[$label]['avg_ms'] = self::int($fields['avg'] ?? null);
            $perConnection[$label]['has_batch'] = 1;
        }

        ksort($perConnection);
        $connections = [];
        foreach ($perConnection as $label => $values) {
            $accepted = $values['accepted'] ?? 0;
            $counted = $accepted + ($values['rejected'] ?? 0) + ($values['failed'] ?? 0) + ($values['unknown'] ?? 0);
            $hasBatch = isset($values['has_batch']);
            $connections[] = [
                'label' => $label,
                'accepted' => $accepted,
                'rejected' => $values['rejected'] ?? 0,
                'failed' => $values['failed'] ?? 0,
                'unknown' => $values['unknown'] ?? 0,
                'deferred' => $values['deferred'] ?? 0,
                'skipped' => $values['skipped'] ?? 0,
                'rate' => $counted > 0 ? round($accepted / $counted * 100, 2) : null,
                'max_ms' => $hasBatch ? ($values['max_ms'] ?? 0) : null,
                'avg_ms' => $hasBatch ? ($values['avg_ms'] ?? 0) : null,
            ];
        }

        $reasonTotals = [];
        foreach ($this->aggregate(MobilePushRecorder::REASON, 'count', limit: 1000) as $row) {
            $fields = self::fields($row);
            $parts = $this->parts($fields['key'] ?? null);
            if (count($parts) !== 3) {
                continue;
            }
            $reasonTotals[$parts[2]] = ($reasonTotals[$parts[2]] ?? 0) + self::int($fields['count'] ?? null);
        }
        arsort($reasonTotals);
        $reasons = [];
        foreach (array_slice($reasonTotals, 0, 10, true) as $reason => $count) {
            $reasons[] = ['reason' => (string) $reason, 'count' => $count];
        }

        $now = time();
        $openCircuits = [];
        foreach ($this->values(MobilePushRecorder::CIRCUIT_STATE) as $key => $value) {
            $parts = $this->parts($key);
            if (count($parts) === 3 && $value->value === 'open') {
                $openCircuits[] = $parts[0].' / '.$parts[1].' ('.str_replace('_', ' ', $parts[2]).')';
            }
        }
        $throttled = [];
        foreach ($this->values(MobilePushRecorder::THROTTLE_STATE) as $key => $value) {
            $parts = $this->parts($key);
            if (count($parts) === 2 && (int) $value->value > $now) {
                $throttled[] = $parts[0].' / '.$parts[1];
            }
        }
        sort($openCircuits);
        sort($throttled);

        return new CardData($connections, $reasons, $openCircuits, $throttled, $this->total(MobilePushRecorder::THROTTLE), $this->total(MobilePushRecorder::CIRCUIT));
    }

    /** @return array<string, mixed> */
    private static function fields(mixed $row): array
    {
        $fields = [];
        foreach (is_object($row) ? get_object_vars($row) : [] as $name => $value) {
            $fields[(string) $name] = $value;
        }

        return $fields;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) round((float) $value) : 0;
    }

    private function total(string $type): int
    {
        $total = $this->aggregateTotal($type, 'count');

        return is_numeric($total) ? (int) $total : 0;
    }

    /** @return list<string> */
    private function parts(mixed $key): array
    {
        $decoded = is_string($key) ? json_decode($key, true) : null;
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $part): string => is_scalar($part) ? (string) $part : '', $decoded));
    }
}
