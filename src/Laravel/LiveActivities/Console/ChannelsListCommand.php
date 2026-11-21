<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Console;

use Padosoft\MobilePush\Laravel\LiveActivities\BroadcastChannel;
use Padosoft\MobilePush\Laravel\LiveActivities\ChannelState;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\InvalidInputException;

final class ChannelsListCommand extends ChannelCommand
{
    protected $signature = 'mobile-push:channels:list
        {--connection= : The push connection (default: mobile-push.live_activities.binding)}
        {--scope= : The scope (default: the current one)}
        {--state= : Only this state (creating, active, deleting, deleted, unknown)}
        {--json : Print the rows as JSON}';

    protected $description = 'List the broadcast channels of a connection (local rows; nothing is sent)';

    protected function perform(): int
    {
        $binding = $this->binding();
        $query = BroadcastChannel::blank()->newQuery()->forBinding($binding);

        if (($state = $this->optionString('state')) !== null) {
            $parsed = ChannelState::tryFrom($state);
            if ($parsed === null) {
                throw new InvalidInputException('--state must be one of: '.implode(', ', array_map(static fn (ChannelState $s): string => $s->value, ChannelState::cases())).'.');
            }
            $query->where('state', $parsed->value);
        }

        $rows = [];
        foreach ($query->orderByDesc('created_at')->orderByDesc('id')->get() as $channel) {
            $rows[] = [
                'id' => $channel->id,
                'channel_id' => $channel->channel_id,
                'state' => $channel->state->value,
                'storage' => $channel->storage_policy->name,
                'label' => $channel->label,
                'publications' => $channel->publications,
                'last_published_at' => self::iso($channel->last_published_at),
                'closed_reason' => $channel->closed_reason?->value,
                'created_at' => self::iso($channel->created_at),
            ];
        }
        $cap = $this->capOf($binding);

        if ($this->wantsJson()) {
            $this->json(['ok' => true, 'count' => count($rows), 'cap' => $cap, 'channels' => $rows]);

            return self::EXIT_OK;
        }

        if ($rows === []) {
            $this->line('No broadcast channel matches.');
        } else {
            $this->table(
                ['Id', 'APNs channel id', 'State', 'Storage', 'Label', 'Publications', 'Last published', 'Closed'],
                array_map(static fn (array $r): array => [$r['id'], $r['channel_id'] ?? '-', $r['state'], $r['storage'], $r['label'] ?? '-', $r['publications'], $r['last_published_at'] ?? '-', $r['closed_reason'] ?? '-'], $rows),
            );
        }
        $this->line($this->capLine($binding));
        if (array_filter($rows, static fn (array $r): bool => $r['state'] === 'unknown') !== []) {
            $this->warn('Some channels are "unknown": their creation outcome was lost. Run mobile-push:channels:sync to resolve them.');
        }

        return self::EXIT_OK;
    }
}
