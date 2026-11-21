<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Commands;

use Padosoft\MobilePush\Laravel\Console\Support\ExplainCatalog;
use Padosoft\MobilePush\Laravel\Console\Support\ExplainEntry;

/**
 * Explains an APNs reason, an FCM error code or a package reason code: cause, remedy and what the package does.
 *
 * Exit codes: 0 explained; 1 the reason is unknown (the closest matches are suggested); 2 invalid input.
 */
final class ExplainCommand extends PushCommand
{
    protected $signature = 'mobile-push:explain
        {reason? : An APNs reason (BadDeviceToken), an FCM error code (UNREGISTERED) or a package reason code (destination_rejected)}
        {--provider= : Restrict to apns, fcm or package}
        {--all : Print the whole catalog as a table}
        {--json : Print the result as JSON}';

    protected $description = 'Explain a push error: cause, remedy and what the package does about it';

    public function handle(): int
    {
        $provider = $this->stringOption('provider');
        if ($provider !== null) {
            $provider = strtolower($provider);
            if (! in_array($provider, ['apns', 'fcm', 'package'], true)) {
                return $this->failWith(sprintf('Unknown --provider "%s".', $provider), 2, 'Use apns, fcm or package.');
            }
        }

        if ((bool) $this->option('all')) {
            return $this->all($provider);
        }

        $reason = $this->argument('reason');
        if (! is_string($reason) || trim($reason) === '') {
            return $this->failWith('Say which reason to explain, or use --all.', 2, 'Example: php artisan mobile-push:explain BadDeviceToken');
        }

        $found = ExplainCatalog::find($reason, $provider);
        if ($found === []) {
            $suggestions = ExplainCatalog::suggest($reason, $provider);
            if ($this->wantsJson()) {
                $this->emitJson(['exit_code' => 1, 'error' => 'unknown reason', 'suggestions' => array_map(static fn (ExplainEntry $e): array => ['provider' => $e->provider, 'reason' => $e->key], $suggestions)]);
            } else {
                $this->components->error(sprintf('"%s" is not a reason the package knows.', $reason));
                $this->line('  Closest matches:');
                foreach ($suggestions as $entry) {
                    $this->line(sprintf('    - %s (%s)', $entry->key, $entry->source));
                }
                $this->line('  List them all with: php artisan mobile-push:explain --all');
            }

            return 1;
        }

        if ($this->wantsJson()) {
            $this->emitJson(['exit_code' => 0, 'entries' => array_map(static fn (ExplainEntry $e): array => $e->toArray(), $found)]);

            return 0;
        }

        foreach ($found as $entry) {
            $this->describe($entry);
        }

        return 0;
    }

    private function describe(ExplainEntry $entry): void
    {
        $this->newLine();
        $this->line(sprintf('<options=bold>%s: %s</>%s', $entry->source, $entry->key, $entry->httpStatus !== null ? sprintf(' (HTTP %d)', $entry->httpStatus) : ''));
        $this->line('  Cause and remedy: '.$entry->meaning);
        $this->line('  Package reason code: '.$entry->code->value);
        if ($entry->status !== null) {
            $this->line('  Resulting status: '.$entry->status->value);
        }
        $this->line('  What the package does:');
        foreach ($entry->behaviour as $line) {
            $this->line('    - '.$line);
        }
    }

    private function all(?string $provider): int
    {
        $entries = array_values(array_filter(ExplainCatalog::all(), static fn (ExplainEntry $e): bool => $provider === null || $e->provider === $provider));

        if ($this->wantsJson()) {
            $this->emitJson(['exit_code' => 0, 'count' => count($entries), 'entries' => array_map(static fn (ExplainEntry $e): array => $e->toArray(), $entries)]);

            return 0;
        }

        $this->table(
            ['Source', 'Reason', 'HTTP', 'Package code', 'Status', 'Cause and remedy', 'What the package does'],
            array_map(static fn (ExplainEntry $e): array => [
                $e->source, $e->key, $e->httpStatus ?? '', $e->code->value, $e->status->value ?? '', $e->meaning, implode(' ', $e->behaviour),
            ], $entries),
        );

        return 0;
    }
}
