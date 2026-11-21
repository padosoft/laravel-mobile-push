<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Console;

use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\Input;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\InvalidInputException;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\TransitionReport;

final class ChannelsPublishCommand extends ChannelCommand
{
    protected $signature = 'mobile-push:channels:publish
        {channel : The local row id or the APNs channel id}
        {--state= : The content state, a JSON object (required for an update)}
        {--end : Publish an end instead of an update}
        {--stale= : Update only: when the content becomes stale (ISO-8601 instant or relative such as "+10 minutes")}
        {--dismiss= : End only: when to remove the ended activity (ISO-8601 instant or relative)}
        {--json : Print the result as JSON}';

    protected $description = 'Publish an update or an end to a broadcast channel (one publication, not N deliveries)';

    protected function perform(): int
    {
        $end = (bool) $this->option('end');
        $state = Input::jsonObject('state', $this->optionString('state'));

        if (! $end && $state === null) {
            throw new InvalidInputException('--state is required for an update: pass the content state as a JSON object, or use --end.');
        }
        if (! $end && $this->optionString('dismiss') !== null) {
            throw new InvalidInputException('--dismiss only applies to --end.');
        }
        if ($end && $this->optionString('stale') !== null) {
            throw new InvalidInputException('--stale only applies to an update, not to --end.');
        }

        $channel = $this->findChannel($this->argString('channel'));
        $handle = $this->manager()->broadcast($channel);
        $pending = $end ? $handle->end($state) : $handle->update((array) $state);

        if (($stale = $this->optionString('stale')) !== null) {
            $pending->staleAt(Input::instant('stale', $stale, $this->now()));
        }
        if (($dismiss = $this->optionString('dismiss')) !== null) {
            $pending->dismissAt(Input::instant('dismiss', $dismiss, $this->now()));
        }

        $binding = $this->safeMode()->bindingOf($channel);
        if ($binding !== null) {
            $decision = $this->safeMode()->decide($binding, OperationKind::BroadcastPublish, $channel->destination());
            if ($decision->isSimulated() || $decision->isBlocked()) {
                return $this->reportDecision($decision, sprintf('publish %s to broadcast channel %s', $end ? 'an end' : 'an update', $channel->channel_id ?? $channel->id));
            }
        }

        $result = $pending->send();

        if ($this->wantsJson()) {
            $this->json(['ok' => TransitionReport::exitCode($result) === 0, 'channel' => $channel->channel_id] + TransitionReport::toArray($result));

            return TransitionReport::exitCode($result);
        }

        foreach (TransitionReport::lines($result) as $line) {
            $this->line($line);
        }
        $this->line(sprintf('Publications accepted by this call: %d. One accepted publication is not N deliveries: APNs fans it out to every subscribed device and reports no count, receipt or per-device result.', $result->publications()));

        return TransitionReport::exitCode($result);
    }
}
