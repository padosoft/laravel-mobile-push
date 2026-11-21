<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Ops;

use Padosoft\MobilePush\Laravel\Privacy\Erasure\OwnerExporter;
use Padosoft\MobilePush\Laravel\Privacy\Erasure\OwnerRef;

/**
 * Writes a JSON document with what the package holds about one owner, inside one scope. Device tokens are masked; clear
 * tokens, receipt markers and message contents are never written.
 *
 * Without --output the document is the only thing printed (safe to pipe). The file is created readable by the
 * current user only, and an existing file is never overwritten.
 */
final class ExportCommand extends OpsCommand
{
    protected $signature = 'mobile-push:export
        {owner : The owner as Type:id, for example "App\\Models\\User:42"}
        {--scope= : Scope (tenant) to export from; default is the current scope}
        {--output= : Write the JSON to this file instead of printing it}';

    protected $description = 'Export what the package holds about one owner as JSON (tokens masked)';

    public function handle(OwnerExporter $exporter): int
    {
        try {
            $owner = OwnerRef::parse((string) $this->argument('owner'));
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }

        $json = json_encode($exporter->export($owner, $this->currentScope()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $path = $this->stringOption('output');
        if ($path === null) {
            $this->line($json);

            return self::SUCCESS;
        }

        if (file_exists($path)) {
            throw new UsageError('The output file already exists; it is never overwritten. Choose another path.');
        }
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new UsageError('The output file cannot be created: check the directory and its permissions.');
        }
        chmod($path, 0600);
        fwrite($handle, $json."\n");
        fclose($handle);

        $this->components->info(sprintf('Wrote %d bytes. The file holds personal data (tokens masked): store and share it accordingly.', strlen($json) + 1));

        return self::SUCCESS;
    }
}
