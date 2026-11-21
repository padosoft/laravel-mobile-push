<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Console;

use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\Input;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\InvalidInputException;

final class UpdateCommand extends ActivityTransitionCommand
{
    protected $signature = 'mobile-push:live-activity:update
        {activity : The activity id (see live-activity:list)}
        {--state= : The full new content state, a JSON object (required)}
        {--stale= : When the content becomes stale: ISO-8601 instant or relative duration such as "+10 minutes"}
        {--title= : An alert title (the update also notifies the person)}
        {--body= : The alert body (needs --title)}
        {--relevance= : Relevance score, a number (0 or more)}
        {--low-priority : Send at priority 5 (does not spend the high-priority allowance)}
        {--json : Print the result as JSON}';

    protected $description = 'Send an update to a Live Activity';

    protected function perform(): int
    {
        $state = Input::jsonObject('state', $this->optionString('state'));
        if ($state === null) {
            throw new InvalidInputException('--state is required for an update: pass the full new content state as a JSON object, for example \'{"eta":5}\'.');
        }
        $title = Input::text($this->option('title'));
        if ($title === null && $this->optionString('body') !== null) {
            throw new InvalidInputException('--body needs --title: an alert without a title is not shown.');
        }

        $activity = $this->activity();
        $pending = $this->manager()->for($activity)->update($state);

        if (($stale = $this->optionString('stale')) !== null) {
            $pending->staleAt(Input::instant('stale', $stale, $this->now()));
        }
        if (($relevance = $this->optionString('relevance')) !== null) {
            $pending->relevanceScore(Input::float('relevance', $relevance, 0.0, 1_000_000.0));
        }
        if ($title !== null) {
            $pending->alert($title, $this->optionString('body'));
        }
        if ((bool) $this->option('low-priority')) {
            $pending->lowPriority();
        }

        return $this->dispatchTransition($activity, 'send an update to', $state, $pending);
    }
}
