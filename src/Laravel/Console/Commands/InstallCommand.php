<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Commands;

use Illuminate\Contracts\Config\Repository;
use Padosoft\MobilePush\Core\Exceptions\PushException;
use Padosoft\MobilePush\Laravel\Console\Support\ConsoleInputException;
use Padosoft\MobilePush\Laravel\Console\Support\EnvChecklist;
use Padosoft\MobilePush\Laravel\Console\Support\HorizonBlock;
use Padosoft\MobilePush\Laravel\Console\Support\InstallPlan;
use Padosoft\MobilePush\Laravel\Queue\QueueSettings;

/**
 * A guided first setup. Interactive: it asks which optional modules and which delivery profile. Non-interactive: it
 * works from --modules and --profile alone (defaults: no optional module, light profile).
 *
 * Exit codes: 0 installed; 2 invalid options (nothing was published).
 */
final class InstallCommand extends PushCommand
{
    protected $signature = 'mobile-push:install
        {--modules= : Comma list of optional modules to enable: devices, live-activities, logs, campaigns, receipts (default: none)}
        {--profile= : Delivery profile: light or durable (default: asked, or light when not interactive)}
        {--force : Overwrite files that were already published}';

    protected $description = 'Guided setup: publish the config and the migrations of the modules you choose, and print the .env, Horizon and doctor steps';

    public function handle(Repository $config): int
    {
        try {
            [$modules, $profile] = $this->choices($config);
        } catch (ConsoleInputException $e) {
            return $this->failWith($e->getMessage(), 2, 'Nothing was published.');
        }
        $force = (bool) $this->option('force');

        $this->components->info('Installing laravel-mobile-push');
        $this->line('  Delivery profile: '.$profile.($profile === 'durable' ? ' (every delivery is recorded in a ledger table before it is sent)' : ' (best effort, no tables)'));
        $this->line('  Optional modules: '.($modules === [] ? 'none' : implode(', ', $modules)));
        $this->newLine();

        $this->publishConfig($force);
        foreach (InstallPlan::migrations($modules, $profile) as $group) {
            $this->publishMigrations($group['label'], $group['tag'], $group['suffixes'], $force);
        }
        if (in_array('campaigns', $modules, true) && ! InstallPlan::campaignsAvailable()) {
            $this->components->warn('The campaigns module is not part of this version of the package: no campaigns migration was published.');
        }
        $this->line('  Run php artisan migrate when you are ready to create the tables.');

        $this->newLine();
        $this->line('<options=bold>1. Environment variables (.env)</>');
        foreach (EnvChecklist::lines($modules, $profile) as $line) {
            $this->line('  '.$line);
        }

        $this->newLine();
        $this->line('<options=bold>2. Workers for queued pushes</>');
        try {
            $settings = QueueSettings::fromConfig($config);
        } catch (PushException) {
            $settings = new QueueSettings;
        }
        $this->line('  With Laravel Horizon, add these supervisors:');
        foreach (explode("\n", HorizonBlock::supervisors($settings)) as $line) {
            $this->line('    '.$line);
        }
        $this->line('  Without Horizon, run these two workers under a process manager (Supervisor, systemd):');
        $default = $config->get('queue.default');
        $connection = $settings->connection ?? (is_string($default) ? $default : 'redis');
        foreach (HorizonBlock::queueWork($settings, $connection) as $command) {
            $this->line('    '.$command);
        }
        $this->line(sprintf('  Keep retry_after of the queue connection (or the SQS visibility timeout) at %d seconds or more: it must exceed the worker timeout. mobile-push:doctor checks the whole chain.', HorizonBlock::minimumRetryAfter($settings)));
        if ($profile === 'durable') {
            $this->line('  The durable profile also needs the scheduler running (php artisan schedule:run every minute): it sweeps stuck deliveries.');
        }

        $this->newLine();
        $this->line('<options=bold>3. Check everything</>');
        $this->line('  php artisan mobile-push:doctor');
        $this->line('  Until MOBILE_PUSH_MODE=live (or APP_ENV=production) every send is simulated and nothing reaches a phone.');

        return 0;
    }

    /**
     * @return array{0: list<string>, 1: string}
     *
     * @throws ConsoleInputException
     */
    private function choices(Repository $config): array
    {
        $profile = $this->stringOption('profile');
        $modulesOption = $this->stringOption('modules');

        if ($profile !== null) {
            $profile = strtolower($profile);
            if (! in_array($profile, InstallPlan::PROFILES, true)) {
                throw new ConsoleInputException(sprintf('Unknown --profile "%s". Use light or durable.', $profile));
            }
        }

        $modules = [];
        if ($modulesOption !== null) {
            foreach (array_filter(array_map('trim', explode(',', strtolower($modulesOption)))) as $module) {
                if (! in_array($module, InstallPlan::MODULES, true)) {
                    throw new ConsoleInputException(sprintf('Unknown module "%s". Choose among: %s.', $module, implode(', ', InstallPlan::MODULES)));
                }
                $modules[$module] = $module;
            }
            $modules = array_values($modules);
        } elseif ($this->canAsk()) {
            $modules = $this->askModules();
        }

        if ($profile === null) {
            $production = $config->get('app.env') === 'production';
            $profile = $this->canAsk()
                ? (string) $this->choice(
                    'Delivery profile. "light": best effort, no tables, fine to start. "durable": every delivery is recorded before it is sent, so a crashed worker never loses or duplicates a push'.($production ? ' (recommended for production)' : ' (recommended for production)'),
                    InstallPlan::PROFILES,
                    $production ? 'durable' : 'light',
                )
                : 'light';
        }

        if (in_array('receipts', $modules, true) && $profile !== 'durable') {
            throw new ConsoleInputException('The receipts module writes to the delivery ledger and needs the durable profile.');
        }

        return [$modules, $profile];
    }

    /** @return list<string> */
    private function askModules(): array
    {
        $questions = [
            'devices' => 'Device registry (store tokens per user, so you can write MobilePush::to($user))?',
            'live-activities' => 'Live Activities (iOS lock-screen activities started and updated from the server)?',
            'logs' => 'Keep a send log in the database (mobile_push_logs)?',
            'campaigns' => 'Campaigns (large sends with caps and progress)?',
            'receipts' => 'Client receipts (the app reports "received" / "opened"; needs the durable profile)?',
        ];
        $selected = [];
        foreach ($questions as $module => $question) {
            if ($this->confirm($question, false)) {
                $selected[] = $module;
            }
        }

        return $selected;
    }

    private function publishConfig(bool $force): void
    {
        $target = config_path('mobile-push.php');
        if (is_file($target) && ! $force) {
            $this->line('  config/mobile-push.php already exists: kept (use --force to overwrite it).');

            return;
        }
        $this->callSilent('vendor:publish', ['--tag' => 'mobile-push-config', '--force' => $force]);
        $this->line('  Published config/mobile-push.php');
    }

    /** @param  list<string>  $suffixes */
    private function publishMigrations(string $label, string $tag, array $suffixes, bool $force): void
    {
        $directory = database_path('migrations');
        $existing = 0;
        foreach ($suffixes as $suffix) {
            if (glob($directory.'/*_'.$suffix) !== [] && glob($directory.'/*_'.$suffix) !== false) {
                $existing++;
            }
        }
        if ($suffixes !== [] && $existing === count($suffixes) && ! $force) {
            $this->line(sprintf('  The %s migration(s) are already in database/migrations: kept (use --force to publish a second copy).', $label));

            return;
        }

        $this->callSilent('vendor:publish', ['--tag' => $tag, '--force' => $force]);
        $this->line(sprintf('  Published the %s migration(s).', $label));
    }
}
