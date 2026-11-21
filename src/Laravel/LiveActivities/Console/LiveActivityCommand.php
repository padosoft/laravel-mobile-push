<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\LiveActivities\Console;

use Illuminate\Console\Command;
use Padosoft\MobilePush\Core\Apns\Channels\ChannelApiException;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Exceptions\ValidationException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Policy\SafeModeDecision;
use Padosoft\MobilePush\Core\Privacy\TokenMasker;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityManager;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\InvalidInputException;
use Padosoft\MobilePush\Laravel\LiveActivities\Console\Support\SafeMode;
use Padosoft\MobilePush\Laravel\LiveActivities\LiveActivitiesConfig;
use Psr\Clock\ClockInterface;

/**
 * Shared behaviour of the Live Activities commands.
 *
 * Exit codes: 0 = done (a simulated run is done: it reports what it would do); 1 = nothing was done or the
 * operation did not succeed (module disabled, blocked by safe mode, APNs refused, outcome unknown, activity closed);
 * 2 = invalid input, detected before any effect.
 */
abstract class LiveActivityCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_FAILED = 1;

    public const EXIT_INVALID = 2;

    /** Every command refuses politely when the module is disabled, and turns bad input into exit code 2. */
    final public function handle(): int
    {
        if (! (bool) config('mobile-push.live_activities.enabled', false)) {
            return $this->refuse('Live Activities are disabled: nothing was done. Set mobile-push.live_activities.enabled to true (and run the module migrations) to use this command.', self::EXIT_FAILED);
        }

        try {
            return $this->perform();
        } catch (InvalidInputException|ConfigurationException|ValidationException $e) {
            return $this->refuse($e->getMessage(), self::EXIT_INVALID);
        } catch (ChannelApiException $e) {
            return $this->refuse($e->getMessage(), self::EXIT_FAILED);
        }
    }

    abstract protected function perform(): int;

    protected function manager(): ActivityManager
    {
        return $this->laravel->make(ActivityManager::class);
    }

    protected function config(): LiveActivitiesConfig
    {
        return $this->laravel->make(LiveActivitiesConfig::class);
    }

    protected function safeMode(): SafeMode
    {
        return $this->laravel->make(SafeMode::class);
    }

    protected function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->laravel->make(ClockInterface::class)->now());
    }

    protected function wantsJson(): bool
    {
        return $this->hasOption('json') && (bool) $this->option('json');
    }

    protected function argString(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    protected function optionString(string $name): ?string
    {
        if (! $this->hasOption($name)) {
            return null;
        }

        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function binding(): BindingRef
    {
        return $this->manager()->binding($this->optionString('connection'), $this->optionString('scope'));
    }

    /** @param  array<array-key, mixed>  $data */
    protected function json(array $data): void
    {
        $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /** One failure line, as JSON when the command has --json and it is set. */
    protected function refuse(string $message, int $exit, string $remedy = ''): int
    {
        if ($this->wantsJson()) {
            $this->json(['ok' => false, 'exit' => $exit, 'error' => $message, 'remedy' => $remedy === '' ? null : $remedy]);

            return $exit;
        }

        $this->error($message);
        if ($remedy !== '') {
            $this->line('Remedy: '.$remedy);
        }

        return $exit;
    }

    /** Reports a simulated or blocked verdict; returns the exit code (simulated: 0, blocked: 1). */
    protected function reportDecision(SafeModeDecision $decision, string $wouldDo): int
    {
        if ($decision->isSimulated()) {
            if ($this->wantsJson()) {
                $this->json(['ok' => true, 'simulated' => true, 'would' => $wouldDo, 'note' => $decision->hint]);

                return self::EXIT_OK;
            }
            $this->warn('Simulation mode: nothing was sent and nothing was written.');
            $this->line('Would: '.$wouldDo);
            $this->line((string) $decision->hint);

            return self::EXIT_OK;
        }

        return $this->refuse('Blocked by safe mode: '.$wouldDo.' was not attempted. '.($decision->hint ?? ''), self::EXIT_FAILED);
    }

    /** The only way a destination is ever shown. */
    protected static function mask(Destination|string|null $destination): string
    {
        if ($destination === null) {
            return '-';
        }

        return TokenMasker::mask(is_string($destination) ? $destination : $destination->value());
    }

    protected static function iso(?\DateTimeInterface $at): ?string
    {
        return $at?->format('Y-m-d\TH:i:sP');
    }

    protected static function isoTimestamp(?int $timestamp): ?string
    {
        return $timestamp === null ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
