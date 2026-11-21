<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Console;

use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\Input;

final class EndCommand extends ActivityTransitionCommand
{
    protected $signature = 'mobile-push:live-activity:end
        {activity : The activity id (see live-activity:list)}
        {--state= : The final content state, a JSON object (Apple recommends sending it)}
        {--dismiss= : When to remove the ended activity: ISO-8601 instant or relative duration such as "+10 minutes"}
        {--json : Print the result as JSON}';

    protected $description = 'End a Live Activity';

    protected function perform(): int
    {
        $state = Input::jsonObject('state', $this->optionString('state'));
        $activity = $this->activity();
        $pending = $this->manager()->for($activity)->end($state);

        if (($dismiss = $this->optionString('dismiss')) !== null) {
            $pending->dismissAt(Input::instant('dismiss', $dismiss, $this->now()));
        }

        return $this->dispatchTransition($activity, 'end', $state, $pending);
    }
}
