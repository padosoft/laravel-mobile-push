<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Ops;

use Padosoft\MobilePush\Laravel\Privacy\Erasure\OwnerEraser;
use Padosoft\MobilePush\Laravel\Privacy\Erasure\OwnerRef;

/**
 * Removes or anonymises what the package holds about one owner, inside one scope. It says per store how many rows were
 * affected and, always, what it cannot reach. It does not make an application compliant with any regulation.
 *
 * Exit code: 0 done, 1 refused or a store failed (the command is idempotent: run it again).
 */
final class ForgetCommand extends OpsCommand
{
    protected $signature = 'mobile-push:forget
        {owner : The owner as Type:id, for example "App\\Models\\User:42"}
        {--scope= : Scope (tenant) to erase in; default is the current scope. Other scopes are never touched}
        {--force : Do not ask for confirmation}
        {--json : Print the report as JSON}';

    protected $description = 'Erase what the package holds about one owner (devices, Live Activities, ledger deliveries, logs)';

    public function handle(OwnerEraser $eraser): int
    {
        try {
            $owner = OwnerRef::parse((string) $this->argument('owner'));
        } catch (\InvalidArgumentException $e) {
            throw new UsageError($e->getMessage());
        }
        $scope = $this->currentScope();

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                throw new UsageError('Refusing to erase without confirmation in a non-interactive run: pass --force.');
            }
            if (! $this->confirm(sprintf('Erase everything this package holds about an owner of type %s in scope "%s"? This cannot be undone.', $owner->type, $scope), false)) {
                $this->components->warn('Aborted: nothing was erased.');

                return self::FAILURE;
            }
        }

        $report = $eraser->forget($owner, $scope);

        if ($this->wantsJson()) {
            $this->emitJson($report->toArray());
        } else {
            $lines = $report->lines();
            $this->components->{$report->succeeded() ? 'info' : 'error'}(array_shift($lines));
            foreach ($lines as $line) {
                $this->line($line);
            }
        }

        return $report->succeeded() ? self::SUCCESS : self::FAILURE;
    }
}
