<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Commands;

use Padosoft\MobilePush\Core\Exceptions\PushException;
use Padosoft\MobilePush\Core\Policy\ModeResolver;
use Padosoft\MobilePush\Laravel\Console\Support\ConnectionInfo;
use Padosoft\MobilePush\Laravel\Console\Support\ConnectionInspector;
use Padosoft\MobilePush\Laravel\Sending\PushManager;

/**
 * Lists the configured connections. Secrets are never shown: only non-secret identities.
 *
 * Exit codes: 0 listed (even when a connection has a configuration problem: it is shown in the table); 2 invalid input.
 */
final class ConnectionsCommand extends PushCommand
{
    protected $signature = 'mobile-push:connections
        {--scope= : Tenant scope used to compute the binding generation (default: mobile-push.scope)}
        {--json : Print the result as JSON}';

    protected $description = 'List the configured push connections (no secret is ever shown)';

    public function handle(ConnectionInspector $inspector, PushManager $manager): int
    {
        try {
            $mode = (new ModeResolver)->resolve($manager->settings()->mode, (string) app()->environment());
        } catch (PushException $e) {
            return $this->failWith($e->getMessage(), 2, 'Set MOBILE_PUSH_MODE to auto, live or simulate.');
        }
        $scope = $this->stringOption('scope');

        $infos = array_map(static fn (string $name): ConnectionInfo => $inspector->inspect($name, $scope), $inspector->names());

        if ($this->wantsJson()) {
            $this->emitJson([
                'exit_code' => 0,
                'effective_mode' => $mode->value,
                'configured_mode' => $manager->settings()->mode,
                'connections' => array_map(static fn (ConnectionInfo $i): array => [
                    'name' => $i->name,
                    'provider' => $i->provider?->value,
                    'app_id' => $i->appId,
                    'environment' => $i->environment,
                    'auth' => $i->auth,
                    'credential' => $i->identity,
                    'has_secondary' => $i->hasSecondary,
                    'generation' => $i->generation,
                    'days_left' => $i->daysLeft,
                    'live' => $i->live,
                    'error' => $i->error,
                ], $infos),
            ]);

            return 0;
        }

        if ($infos === []) {
            $this->components->warn('No connection is configured under mobile-push.connections. Run php artisan mobile-push:install for the variables to set.');

            return 0;
        }

        $this->line(sprintf('Effective mode: <options=bold>%s</> (configured "%s", APP_ENV "%s").', $mode->value, $manager->settings()->mode, app()->environment()));
        $this->table(
            ['Name', 'Provider', 'App id', 'Env', 'Auth', 'Credential', 'Generation', 'Live outside production', 'Days left'],
            array_map(fn (ConnectionInfo $i): array => [
                $i->name,
                $i->provider?->value ?? '?',
                $i->appId ?? '-',
                $i->environment ?? '-',
                $i->auth ?? '-',
                ($i->identity ?? '-').($i->hasSecondary ? ' (+ secondary)' : ''),
                $i->generation ?? '-',
                $this->live($i),
                $i->daysLeft === null ? '-' : (string) $i->daysLeft,
            ], $infos),
        );
        foreach ($infos as $info) {
            if ($info->error !== null) {
                $this->components->error(sprintf('"%s": %s', $info->name, $info->error));
            }
        }

        return 0;
    }

    private function live(ConnectionInfo $info): string
    {
        $live = $info->live;
        if (! $live['allow']) {
            return 'blocked (live.allow off)';
        }
        $parts = ['allowed', 'only_to: '.$live['only_to']];
        foreach (['allow_topics' => 'topics', 'allow_broadcast' => 'broadcast', 'allow_channel_management' => 'channels'] as $key => $label) {
            if ($live[$key]) {
                $parts[] = $label;
            }
        }

        return implode(', ', $parts);
    }
}
