<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Ops;

use Illuminate\Console\Command;
use Padosoft\MobilePush\Laravel\Privacy\Erasure\OwnerStores;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared behaviour of the operational commands: usage errors become a printed refusal with exit code 1, JSON output,
 * option parsing and the durable-profile guard. No command prints a device token, a bearer, a key or a marker.
 */
abstract class OpsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return (int) parent::execute($input, $output);
        } catch (UsageError $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /** @throws UsageError */
    protected function requireDurable(string $what): void
    {
        $profile = config('mobile-push.delivery.profile', 'light');
        if ($profile !== 'durable') {
            throw new UsageError($what.' needs the durable delivery profile (the light profile keeps no ledger). Set MOBILE_PUSH_DELIVERY_PROFILE=durable, publish and run the ledger migrations, then run it again.');
        }
    }

    protected function currentScope(): string
    {
        return (new OwnerStores($this->laravel))->scope($this->stringOption('scope'));
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return list<string>
     */
    protected function listOption(string $name): array
    {
        $value = $this->option($name);

        return is_array($value) ? array_values(array_filter(array_map(static fn (mixed $v): string => is_string($v) ? trim($v) : '', $value), static fn (string $v): bool => $v !== '')) : [];
    }

    /** @throws UsageError */
    protected function intOption(string $name, int $min = 0): ?int
    {
        $value = $this->stringOption($name);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < $min) {
            throw new UsageError(sprintf('--%s must be a whole number of at least %d.', $name, $min));
        }

        return (int) $value;
    }

    protected function wantsJson(): bool
    {
        return $this->hasOption('json') && $this->option('json') === true;
    }

    /** @param  array<string, mixed>  $data */
    protected function emitJson(array $data): void
    {
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * JSON with --json, otherwise `key: value` lines (nested keys joined by a dot).
     *
     * @param  array<string, mixed>  $data
     */
    protected function emitSummary(string $title, array $data): void
    {
        if ($this->wantsJson()) {
            $this->emitJson($data);

            return;
        }

        $this->components->info($title);
        foreach (self::flatten($data) as $key => $value) {
            $this->components->twoColumnDetail($key, $value);
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, string>
     */
    public static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                if ($value === []) {
                    $out[$name] = '-';

                    continue;
                }
                $out += self::flatten($value, $name);

                continue;
            }
            $out[$name] = match (true) {
                $value === null => '-',
                is_bool($value) => $value ? 'yes' : 'no',
                is_scalar($value) => (string) $value,
                default => '?',
            };
        }

        return $out;
    }
}
