<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Commands;

use Illuminate\Console\Command;

/** Shared helpers of the mobile-push commands. */
abstract class PushCommand extends Command
{
    protected function wantsJson(): bool
    {
        return $this->hasOption('json') && (bool) $this->option('json');
    }

    /** @param  array<mixed>  $data */
    protected function emitJson(array $data): void
    {
        $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** Whether questions can be answered by a person now. */
    protected function canAsk(): bool
    {
        return $this->input->isInteractive();
    }

    /** Prints a failure (plain text, or a JSON document in --json mode) and returns the exit code. */
    protected function failWith(string $message, int $exit = 2, ?string $remedy = null): int
    {
        if ($this->wantsJson()) {
            $this->emitJson(['exit_code' => $exit, 'error' => $message, 'remedy' => $remedy]);

            return $exit;
        }
        $this->components->error($message);
        if ($remedy !== null) {
            $this->line('  Remedy: '.$remedy);
        }

        return $exit;
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
