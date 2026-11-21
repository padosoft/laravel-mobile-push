<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Console;

use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\InvalidInputException;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\TransitionReport;
use Padosoft\MobilePush\Laravel\LiveActivities\PendingTransition;
use Padosoft\MobilePush\Laravel\LiveActivities\PushActivity;

/** The update and the end of one activity: find it, ask safe mode, send, tell the outcome. */
abstract class ActivityTransitionCommand extends LiveActivityCommand
{
    protected function activity(): PushActivity
    {
        $id = $this->argString('activity');
        $activity = $this->manager()->registry()->find($id);
        if ($activity === null) {
            throw new InvalidInputException('No Live Activity with the id "'.$id.'": list them with mobile-push:live-activity:list.');
        }

        return $activity;
    }

    /** @param  array<string, mixed>|null  $state */
    protected function dispatchTransition(PushActivity $activity, string $verb, ?array $state, PendingTransition $pending): int
    {
        $binding = $this->safeMode()->bindingOf($activity);
        $wouldDo = sprintf('%s activity %s (%s, update token %s)%s', $verb, $activity->id, $activity->state->value, self::mask($activity->destination()), $state === null ? '' : ' with state '.json_encode($state, JSON_UNESCAPED_SLASHES));

        if ($binding !== null) {
            $decision = $this->safeMode()->decide($binding, OperationKind::Send, $activity->destination());
            if ($decision->isSimulated() || $decision->isBlocked()) {
                return $this->reportDecision($decision, $wouldDo);
            }
        }

        $result = $pending->send();

        if ($this->wantsJson()) {
            $this->json(['ok' => TransitionReport::exitCode($result) === 0, 'activity' => $activity->id] + TransitionReport::toArray($result));

            return TransitionReport::exitCode($result);
        }

        foreach (TransitionReport::lines($result) as $line) {
            $this->line($line);
        }

        return TransitionReport::exitCode($result);
    }
}
