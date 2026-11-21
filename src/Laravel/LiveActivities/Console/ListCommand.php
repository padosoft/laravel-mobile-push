<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Console;

use Padosoft\MobilePush\Laravel\LiveActivities\ActivityState;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\Input;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\InvalidInputException;
use Padosoft\MobilePush\Laravel\LiveActivities\PushActivity;

final class ListCommand extends LiveActivityCommand
{
    protected $signature = 'mobile-push:live-activity:list
        {--owner= : Only this owner (Type:id)}
        {--type= : Only this type key}
        {--state= : Only this server state (requested, active, ending, ended, expired)}
        {--scope= : Only this scope}
        {--limit=50 : How many rows (1 to 1000), newest first}
        {--json : Print the rows as JSON}';

    protected $description = 'List Live Activities (tokens are masked)';

    protected function perform(): int
    {
        $limit = Input::positiveInt('limit', $this->option('limit'), 1, 1000);
        $query = PushActivity::blank()->newQuery();

        if (($owner = $this->optionString('owner')) !== null) {
            $model = Input::ownerModel($owner);
            $query->where('owner_type', $model->getMorphClass())->where('owner_id', PushActivity::keyOf($model));
        }
        if (($type = $this->optionString('type')) !== null) {
            $query->where('type_key', $type);
        }
        if (($state = $this->optionString('state')) !== null) {
            $parsed = ActivityState::tryFrom($state);
            if ($parsed === null) {
                throw new InvalidInputException('--state must be one of: '.implode(', ', array_map(static fn (ActivityState $s): string => $s->value, ActivityState::cases())).'.');
            }
            $query->where('state', $parsed->value);
        }
        if (($scope = $this->optionString('scope')) !== null) {
            $query->where('scope', $scope);
        }

        $rows = [];
        foreach ($query->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get() as $activity) {
            $rows[] = [
                'id' => $activity->id,
                'type' => $activity->type_key,
                'scope' => $activity->scope,
                'owner' => $activity->owner_type === null ? null : $activity->owner_type.':'.$activity->owner_id,
                'state' => $activity->state->value,
                'last_event' => $activity->last_event,
                'last_status' => $activity->last_status,
                'last_timestamp' => $activity->last_timestamp,
                'last_timestamp_iso' => self::isoTimestamp($activity->last_timestamp),
                'started_at' => self::iso($activity->started_at),
                'local_expires_at' => self::iso($activity->local_expires_at),
                'end_accepted_at' => self::iso($activity->end_accepted_at),
                'closed_reason' => $activity->closed_reason?->value,
                'client_reported_state' => $activity->client_reported_state?->value,
                'client_reported_at' => self::iso($activity->client_reported_at),
                'update_token' => $this->maskedToken($activity),
                'token_state' => $activity->token_state?->value,
            ];
        }

        if ($this->wantsJson()) {
            $this->json(['ok' => true, 'count' => count($rows), 'activities' => $rows]);

            return self::EXIT_OK;
        }

        if ($rows === []) {
            $this->line('No Live Activity matches.');

            return self::EXIT_OK;
        }

        $this->table(
            ['Id', 'Type', 'State', 'Last sent', 'Started', 'Local expiry', 'End accepted', 'Client says', 'Update token'],
            array_map(static fn (array $r): array => [
                $r['id'], $r['type'], $r['state'],
                $r['last_event'] === null ? '-' : $r['last_event'].' '.$r['last_status'].' @ '.($r['last_timestamp_iso'] ?? '-'),
                $r['started_at'] ?? '-', $r['local_expires_at'] ?? '-',
                $r['end_accepted_at'] === null ? 'no' : 'yes, '.$r['end_accepted_at'],
                $r['client_reported_state'] ?? 'not reported',
                ($r['update_token'] ?? '-').($r['token_state'] === null || $r['token_state'] === 'active' ? '' : ' ['.$r['token_state'].']'),
            ], $rows),
        );
        $this->line(sprintf('%d activit%s. "State" is what the server did; "Client says" is what the app last reported, which the server cannot observe on its own.', count($rows), count($rows) === 1 ? 'y' : 'ies'));

        return self::EXIT_OK;
    }

    private function maskedToken(PushActivity $activity): ?string
    {
        try {
            return $activity->update_token === null ? null : self::mask($activity->destination());
        } catch (\Throwable) {
            return '[unreadable]';
        }
    }
}
