<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Console;

use Illuminate\Console\Command;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Exceptions\ValidationException;
use Padosoft\MobilePush\Core\Identity\Scope;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignManager;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignNotFoundException;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignSettings;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStateException;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignStore;
use Padosoft\MobilePush\Laravel\Campaigns\Console\Support\InvalidInputException;
use Padosoft\MobilePush\Laravel\Sending\PushManager;

/**
 * Shared behaviour of the campaign commands.
 *
 * Exit codes: 0 = done / consistent; 1 = refused (module disabled, light profile), not allowed in the campaign's
 * current state, declined, or a difference found; 2 = invalid input or campaign not found (in this scope).
 */
abstract class CampaignCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_REFUSED = 1;

    public const EXIT_INVALID = 2;

    final public function handle(): int
    {
        try {
            $this->laravel->make(CampaignSettings::class)->assertUsable();

            return $this->perform();
        } catch (ConfigurationException $e) {
            return $this->refuse($e->getMessage(), self::EXIT_REFUSED);
        } catch (CampaignStateException $e) {
            return $this->refuse($e->getMessage(), self::EXIT_REFUSED);
        } catch (CampaignNotFoundException $e) {
            return $this->refuse($e->getMessage().' Check the id with mobile-push:campaign:list (and --scope).', self::EXIT_INVALID);
        } catch (InvalidInputException|ValidationException $e) {
            return $this->refuse($e->getMessage(), self::EXIT_INVALID);
        }
    }

    abstract protected function perform(): int;

    protected function manager(): CampaignManager
    {
        return $this->laravel->make(CampaignManager::class);
    }

    protected function store(): CampaignStore
    {
        return $this->laravel->make(CampaignStore::class);
    }

    protected function settings(): CampaignSettings
    {
        return $this->laravel->make(CampaignSettings::class);
    }

    /** --scope, or the current scope of the application. */
    protected function scope(): string
    {
        $given = $this->optionString('scope');

        return $given === null ? $this->laravel->make(PushManager::class)->currentScope() : Scope::normalize($given);
    }

    protected function wantsJson(): bool
    {
        return $this->hasOption('json') && (bool) $this->option('json');
    }

    protected function campaignId(): string
    {
        $value = $this->argument('campaign');
        $id = is_string($value) ? trim($value) : '';
        if ($id === '') {
            throw new InvalidInputException('Give the campaign id (see mobile-push:campaign:list).');
        }

        return $id;
    }

    protected function optionString(string $name): ?string
    {
        if (! $this->hasOption($name)) {
            return null;
        }
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function intOption(string $name, int $min, int $max): ?int
    {
        $raw = $this->optionString($name);
        if ($raw === null) {
            return null;
        }
        if (preg_match('/^\d+$/', $raw) !== 1 || (int) $raw < $min || (int) $raw > $max) {
            throw new InvalidInputException("--{$name} must be a whole number between {$min} and {$max}.");
        }

        return (int) $raw;
    }

    /** @param  array<array-key, mixed>  $data */
    protected function json(array $data, bool $pretty = true): void
    {
        $this->line((string) json_encode($data, ($pretty ? JSON_PRETTY_PRINT : 0) | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    protected function refuse(string $message, int $exit): int
    {
        if ($this->wantsJson()) {
            $this->json(['ok' => false, 'exit' => $exit, 'error' => $message]);

            return $exit;
        }
        $this->error($message);

        return $exit;
    }
}
