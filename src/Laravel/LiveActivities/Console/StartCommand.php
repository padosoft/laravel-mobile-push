<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Console;

use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityRegistry;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\Input;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\InvalidInputException;
use Padosoft\MobilePush\Laravel\LiveActivities\TypeRegistry;

final class StartCommand extends LiveActivityCommand
{
    protected $signature = 'mobile-push:live-activity:start
        {type : A Live Activity type key from mobile-push.live_activities.types}
        {--owner= : The owner as Type:id (a model class or morph alias, then its key)}
        {--attributes= : The static attributes, a JSON object}
        {--state= : The initial content state, a JSON object}
        {--title= : The alert title (required: a remote start must tell the person)}
        {--body= : The alert body}
        {--connection= : The push connection (default: mobile-push.live_activities.binding)}
        {--scope= : The scope (default: the current one)}
        {--input-push-token : Ask the device to report an update token (iOS 18+)}
        {--channel= : Subscribe the started activity to this broadcast channel id (iOS 18+)}
        {--low-priority : Send at priority 5}
        {--json : Print the result as JSON}';

    protected $description = 'Start a Live Activity remotely on the push-to-start tokens of an owner';

    protected function perform(): int
    {
        $title = Input::text($this->option('title'));
        if ($title === null) {
            throw new InvalidInputException('--title is required: APNs shows a remotely started Live Activity only together with an alert, so the person is told it began. Nothing was sent.');
        }
        $ownerRaw = Input::text($this->option('owner'));
        if ($ownerRaw === null) {
            throw new InvalidInputException('--owner=Type:id is required: a start goes to the push-to-start tokens of one owner. Nothing was sent.');
        }

        $attributes = Input::jsonObject('attributes', $this->optionString('attributes')) ?? [];
        $state = Input::jsonObject('state', $this->optionString('state')) ?? [];
        $owner = Input::ownerModel($ownerRaw);
        $type = $this->laravel->make(TypeRegistry::class)->resolve($this->argString('type'));
        $binding = $this->binding();
        $tokens = $this->laravel->make(ActivityRegistry::class)->pushToStartTokensFor($binding, $this->laravel->make(TypeRegistry::class)->keyOf($type), [$owner]);

        if ($tokens === []) {
            return $this->refuse(
                'No active push-to-start token for this owner and type in this scope and connection: nothing was sent.',
                self::EXIT_FAILED,
                'The app must report its push-to-start token (iOS 17.2+) with registerPushToStartToken().',
            );
        }

        $wouldDo = sprintf('start "%s" for %s on %d push-to-start token(s): %s', $type->swiftName(), $ownerRaw, count($tokens), implode(', ', array_map(static fn ($t): string => self::mask($t->destination()), $tokens)));

        // Same guard as the pipeline, asked first: a simulated or blocked start must not write `requested` rows.
        $allowed = 0;
        $decision = null;
        foreach ($tokens as $token) {
            $decision = $this->safeMode()->decide($binding, OperationKind::Send, $token->destination());
            if ($decision->isSimulated()) {
                return $this->reportDecision($decision, $wouldDo);
            }
            $allowed += $decision->allowed ? 1 : 0;
        }
        if ($allowed === 0) {
            return $this->reportDecision($decision, $wouldDo);
        }

        $pending = $this->manager()->start($type, $attributes, $state)->alert($title, $this->optionString('body'))->to($owner);
        $connection = $this->optionString('connection');
        $scope = $this->optionString('scope');
        if ($connection !== null) {
            $pending->connection($connection);
        }
        if ($scope !== null) {
            $pending->scope($scope);
        }
        if ((bool) $this->option('input-push-token')) {
            $pending->inputPushToken();
        }
        if ($this->optionString('channel') !== null) {
            $pending->inputPushChannel($this->optionString('channel'));
        }
        if ((bool) $this->option('low-priority')) {
            $pending->lowPriority();
        }

        $result = $pending->send();
        $rows = [];
        $failures = 0;
        $unknown = 0;
        foreach ($result->results as $i => $sent) {
            $activity = $result->activities[$i] ?? null;
            $rows[] = [
                'token' => self::mask($sent->destination),
                'status' => $sent->status->value,
                'reason' => $sent->reason->code->value.($sent->reason->rawReason === null ? '' : ' ('.$sent->reason->rawReason.')'),
                'activity' => $activity?->id,
            ];
            $failures += $sent->status === SendStatus::Accepted ? 0 : 1;
            $unknown += $sent->status === SendStatus::OutcomeUnknown ? 1 : 0;
        }

        if ($this->wantsJson()) {
            $this->json(['ok' => $failures === 0, 'accepted' => $result->accepted(), 'results' => $rows]);

            return $failures === 0 ? self::EXIT_OK : self::EXIT_FAILED;
        }

        $this->table(['Push-to-start token', 'Status', 'Reason', 'Activity id'], array_map(static fn (array $r): array => [$r['token'], $r['status'], $r['reason'], $r['activity'] ?? '-'], $rows));
        $this->line(sprintf('%d of %d start request(s) accepted by APNs. Accepted means APNs took the request, not that the activity is on the screen: the app reports its update token when the activity really starts.', $result->accepted(), count($rows)));
        if ($unknown > 0) {
            $this->warn('For '.$unknown.' token(s) the outcome is unknown. A start is never retried (it would show two activities): the activity row stays "requested", and attaches to the token report if the device did start it.');
        }

        return $failures === 0 ? self::EXIT_OK : self::EXIT_FAILED;
    }
}
