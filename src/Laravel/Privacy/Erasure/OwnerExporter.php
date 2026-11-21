<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Privacy\Erasure;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Ledger\LedgerTime;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenMasker;
use Psr\Clock\ClockInterface;

/**
 * "Export my data": what this package holds about one owner inside one scope, as plain arrays ready for
 * `json_encode()`. Device tokens appear ONLY masked; receipt markers, encrypted envelopes, keyed indexes and message
 * snapshots are never exported (the export says whether a snapshot is stored, not what it says).
 *
 * Every store is capped at `$limit` rows and the section says so when it was cut (`truncated`).
 */
final class OwnerExporter
{
    public const DEFAULT_LIMIT = 10_000;

    private readonly OwnerStores $stores;

    public function __construct(private readonly Container $container)
    {
        $this->stores = new OwnerStores($container);
    }

    /** @return array<string, mixed> */
    public function export(OwnerRef $owner, ?string $scope = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $scope = $this->stores->scope($scope);
        $limit = max(1, $limit);
        $now = $this->container->make(ClockInterface::class)->now();

        return [
            'owner' => ['type' => $owner->type, 'id' => $owner->id],
            'scope' => $scope,
            'generated_at' => $now->format(DATE_ATOM),
            'devices' => $this->section(fn (): array => $this->devices($owner, $scope, $limit)),
            'live_activities' => $this->section(fn (): array => $this->liveActivities($owner, $scope, $limit)),
            'deliveries' => $this->section(fn (): array => $this->deliveries($owner, $scope, $limit)),
            'effects' => $this->section(fn (): array => $this->effects($owner, $scope, $limit)),
            'send_log' => $this->section(fn (): array => $this->logs($owner, $scope, $limit)),
            'notes' => [
                'Device tokens are masked; the clear tokens, receipt markers and message contents are never exported.',
                'Not covered: '.implode(' ', ErasureReport::NOT_REACHED),
            ],
        ];
    }

    /**
     * @param  \Closure(): array{rows: list<array<string, mixed>>, truncated: bool}|null  $read
     * @return array{status: string, truncated?: bool, rows?: list<array<string, mixed>>}
     */
    private function section(\Closure $read): array
    {
        try {
            $out = $read();
        } catch (\Throwable $e) {
            return ['status' => 'failed: '.$e::class];
        }

        return ['status' => 'ok', 'truncated' => $out['truncated'], 'rows' => $out['rows']];
    }

    /** @return array{rows: list<array<string, mixed>>, truncated: bool} */
    private function devices(OwnerRef $owner, string $scope, int $limit): array
    {
        $query = $this->stores->devices();
        if ($query === null) {
            return ['rows' => [], 'truncated' => false];
        }

        $rows = [];
        foreach ($this->owned($query, $owner, $scope)->orderBy('id')->limit($limit + 1)->get() as $device) {
            $kind = (string) $device->getAttribute('kind')->value;
            $rows[] = [
                'id' => $device->getKey(),
                'provider' => $device->getAttribute('provider')->value,
                'platform' => $device->getAttribute('platform')?->value,
                'app_id' => $device->getAttribute('app_id'),
                'environment' => $device->getAttribute('environment')->value,
                'kind' => $kind,
                'token' => $this->masked($device->getAttribute('token'), $scope, $kind),
                'fid' => $device->getAttribute('fid') === null ? null : $this->masked($device->getAttribute('fid'), $scope, DestinationKind::FcmFid->value),
                'generation' => $device->getAttribute('generation'),
                'state' => $device->getAttribute('state')->value,
                'state_reason' => $device->getAttribute('state_reason'),
                'registered_at' => $this->date($device->getAttribute('registered_at')),
                'last_seen_at' => $this->date($device->getAttribute('last_seen_at')),
                'last_sent_at' => $this->date($device->getAttribute('last_sent_at')),
                'locale' => $device->getAttribute('locale'),
                'timezone' => $device->getAttribute('timezone'),
                'app_version' => $device->getAttribute('app_version'),
                'os_version' => $device->getAttribute('os_version'),
                'device_model' => $device->getAttribute('device_model'),
                'metadata' => $device->getAttribute('metadata'),
            ];
        }

        return $this->cut($rows, $limit);
    }

    /** @return array{rows: list<array<string, mixed>>, truncated: bool} */
    private function liveActivities(OwnerRef $owner, string $scope, int $limit): array
    {
        $rows = [];
        $activities = $this->stores->activities();
        if ($activities !== null) {
            foreach ($this->owned($activities, $owner, $scope)->orderBy('id')->limit($limit + 1)->get() as $row) {
                $rows[] = [
                    'kind' => 'activity',
                    'id' => $row->getKey(),
                    'app_id' => $row->getAttribute('app_id'),
                    'type_key' => $row->getAttribute('type_key'),
                    'state' => $this->enum($row->getAttribute('state')),
                    'update_token' => $row->getAttribute('update_token') === null ? null : $this->masked($row->getAttribute('update_token'), $scope, DestinationKind::ApnsLiveActivityToken->value),
                    'started_at' => $this->date($row->getAttribute('started_at')),
                    'closed_at' => $this->date($row->getAttribute('closed_at')),
                ];
            }
        }
        $starts = $this->stores->pushToStartTokens();
        if ($starts !== null) {
            foreach ($this->owned($starts, $owner, $scope)->orderBy('id')->limit($limit + 1)->get() as $row) {
                $rows[] = [
                    'kind' => 'push_to_start_token',
                    'id' => $row->getKey(),
                    'app_id' => $row->getAttribute('app_id'),
                    'type_key' => $row->getAttribute('type_key'),
                    'state' => $this->enum($row->getAttribute('state')),
                    'token' => $this->masked($row->getAttribute('token'), $scope, DestinationKind::ApnsPushToStartToken->value),
                    'registered_at' => $this->date($row->getAttribute('registered_at')),
                ];
            }
        }

        return $this->cut($rows, $limit);
    }

    /** @return array{rows: list<array<string, mixed>>, truncated: bool} */
    private function deliveries(OwnerRef $owner, string $scope, int $limit): array
    {
        $query = $this->stores->deliveries();
        if ($query === null) {
            return ['rows' => [], 'truncated' => false];
        }

        $rows = [];
        foreach ($query->where('scope', $scope)->where('owner_type', $owner->type)->where('owner_id', $owner->id)->orderBy('id')->limit($limit + 1)->get() as $row) {
            $rows[] = [
                'id' => $row->id,
                'effect_id' => $row->effect_id,
                'kind' => $row->kind,
                'device_id' => $row->device_id,
                'state' => $row->state,
                'attempts' => (int) $row->attempts,
                'reason_code' => $row->reason_code,
                'raw_reason' => $row->raw_reason,
                'http_status' => $row->http_status,
                'destination_action' => $row->destination_action,
                'provider_message_id' => $row->provider_message_id,
                'campaign_id' => $row->campaign_id,
                'destination_stored' => $row->destination !== null,
                'created_at' => $this->ms($row->created_at),
                'updated_at' => $this->ms($row->updated_at),
                'accepted_at' => $this->ms($row->accepted_at),
                'client_received_at' => $this->ms($row->client_received_at),
                'client_opened_at' => $this->ms($row->client_opened_at),
            ];
        }

        return $this->cut($rows, $limit);
    }

    /** @return array{rows: list<array<string, mixed>>, truncated: bool} */
    private function effects(OwnerRef $owner, string $scope, int $limit): array
    {
        $query = $this->stores->effects();
        if ($query === null) {
            return ['rows' => [], 'truncated' => false];
        }

        $rows = [];
        foreach ($query->where('scope', $scope)->where('origin_type', $owner->type)->where('origin_id', $owner->id)->orderBy('id')->limit($limit + 1)->get() as $row) {
            $rows[] = [
                'id' => $row->id,
                'notification_class' => $row->notification_class,
                'operation' => $row->operation,
                'traffic_class' => $row->traffic_class,
                'campaign_id' => $row->campaign_id,
                'message_snapshot_stored' => $row->message !== null,
                'created_at' => $this->ms($row->created_at),
                'expires_at' => $this->ms($row->expires_at),
            ];
        }

        return $this->cut($rows, $limit);
    }

    /** @return array{rows: list<array<string, mixed>>, truncated: bool} */
    private function logs(OwnerRef $owner, string $scope, int $limit): array
    {
        $query = $this->stores->logs();
        if ($query === null) {
            return ['rows' => [], 'truncated' => false];
        }

        $columns = ['id', 'created_at', 'provider', 'binding_name', 'app_id', 'environment', 'operation', 'push_type', 'traffic_class', 'destination_kind', 'destination_masked', 'status', 'reason_code', 'reason_raw', 'http_status', 'provider_message_id', 'attempts', 'payload_level', 'payload', 'effect_id', 'delivery_id', 'campaign_id', 'notification'];
        $rows = [];
        foreach ($this->logOwned($query, $owner, $scope)->orderBy('id')->limit($limit + 1)->get($columns) as $row) {
            /** @var array<string, mixed> $values */
            $values = $row->only($columns);
            $rows[] = $values;
        }

        return $this->cut($rows, $limit);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function owned(Builder $query, OwnerRef $owner, string $scope): Builder
    {
        return $query->where('scope', $scope)->where('owner_type', $owner->type)->where('owner_id', $owner->id);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function logOwned(Builder $query, OwnerRef $owner, string $scope): Builder
    {
        return $query->where('scope', $scope)->where('origin_type', $owner->type)->where('origin_id', $owner->id);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, truncated: bool}
     */
    private function cut(array $rows, int $limit): array
    {
        return ['rows' => array_slice($rows, 0, $limit), 'truncated' => count($rows) > $limit];
    }

    private function masked(mixed $envelope, string $scope, string $kind): string
    {
        if (! is_string($envelope)) {
            return '[unreadable]';
        }

        try {
            return TokenMasker::mask($this->container->make(TokenCipher::class)->decrypt($envelope, $scope, $kind));
        } catch (\Throwable) {
            return '[unreadable]';
        }
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }

    private function ms(mixed $value): ?string
    {
        return is_numeric($value) ? LedgerTime::date((int) $value)->format(DATE_ATOM) : null;
    }

    private function enum(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
