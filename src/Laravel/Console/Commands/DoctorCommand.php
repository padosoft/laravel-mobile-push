<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Commands;

use Padosoft\MobilePush\Core\Exceptions\PushException;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Policy\ExecutionMode;
use Padosoft\MobilePush\Laravel\Console\Support\Doctor;
use Padosoft\MobilePush\Laravel\Console\Support\DoctorCheck;
use Padosoft\MobilePush\Laravel\Console\Support\DoctorStatus;

/**
 * Exit codes: 0 everything ok, 1 warnings (or something that cannot be verified), 2 at least one failure
 * (or --live refused / invalid input).
 */
final class DoctorCommand extends PushCommand
{
    protected $signature = 'mobile-push:doctor
        {connection? : Check only this connection}
        {--live : Also send REAL probe requests (one per connection) to prove the authentication works}
        {--json : Print the result as JSON}';

    protected $description = 'Check the environment, configuration, credentials, cache, queue timeouts and database of the push package';

    public function handle(Doctor $doctor): int
    {
        $only = $this->argument('connection');
        $only = is_string($only) && $only !== '' ? $only : null;
        $live = (bool) $this->option('live');

        if ($live) {
            try {
                $mode = $doctor->mode();
            } catch (PushException $e) {
                return $this->failWith($e->getMessage(), 2, 'Set MOBILE_PUSH_MODE to auto, live or simulate.');
            }
            if ($mode === ExecutionMode::Simulate) {
                return $this->failWith(
                    '--live is refused: the effective mode is "simulate", so no request would leave this machine and the probe would prove nothing.',
                    2,
                    'Set MOBILE_PUSH_MODE=live (outside production also set live.allow=true on the connection), then run the doctor again.',
                );
            }
        }

        $checks = $doctor->run($only);

        if ($live) {
            $targets = $doctor->liveTargets($only);
            if (! $this->wantsJson()) {
                $this->newLine();
                $this->components->warn('LIVE PROBE: the next step sends REAL requests ('.count($targets).' in total, one per connection) to the providers.');
                foreach ($targets as $info) {
                    $this->line($info->provider === Provider::Apns
                        ? sprintf('  - "%s": one push to an obviously invalid APNs device token; APNs is expected to answer BadDeviceToken, which proves the authentication works. APNs counts this request.', $info->name)
                        : sprintf('  - "%s": one FCM validate_only call (validated by Google, never delivered to a device).', $info->name));
                }
                $this->newLine();
            }
            $checks = [...$checks, ...$doctor->live($targets)];
            if ($targets === []) {
                $checks[] = DoctorCheck::warning('Live probe', 'No connection could be probed (none configured, or their credentials failed the checks above).', 'Fix the connection errors first.');
            }
        }

        $exit = Doctor::exitCode($checks);

        if ($this->wantsJson()) {
            $this->emitJson([
                'exit_code' => $exit,
                'live' => $live,
                'checks' => array_map(static fn (DoctorCheck $c): array => $c->toArray(), $checks),
            ]);

            return $exit;
        }

        $this->render($checks);
        $this->summary($checks, $exit);

        return $exit;
    }

    /** @param  list<DoctorCheck>  $checks */
    private function render(array $checks): void
    {
        $section = null;
        foreach ($checks as $check) {
            if ($check->section !== $section) {
                $section = $check->section;
                $this->newLine();
                $this->line('<options=bold>'.$section.'</>');
            }
            $this->line(sprintf('  %s %s', $this->symbol($check->status), $check->label));
            if ($check->remedy !== null) {
                $this->line('      Remedy: '.$check->remedy);
            }
        }
    }

    /** @param  list<DoctorCheck>  $checks */
    private function summary(array $checks, int $exit): void
    {
        $count = static fn (DoctorStatus $status): int => count(array_filter($checks, static fn (DoctorCheck $c): bool => $c->status === $status));
        $this->newLine();
        $this->line(sprintf(
            '%d ok, %d warning(s), %d not verifiable, %d failure(s).',
            $count(DoctorStatus::Ok), $count(DoctorStatus::Warning), $count(DoctorStatus::NotVerifiable), $count(DoctorStatus::Failed),
        ));
        $this->line(match ($exit) {
            0 => 'Result: everything checked is fine (exit 0).',
            1 => 'Result: warnings or checks that could not be verified (exit 1).',
            default => 'Result: failures found, fix them before sending (exit 2).',
        });
    }

    private function symbol(DoctorStatus $status): string
    {
        return match ($status) {
            DoctorStatus::Ok => '<fg=green>'.$status->symbol().'</>',
            DoctorStatus::Info => '<fg=cyan>'.$status->symbol().'</>',
            DoctorStatus::Warning => '<fg=yellow>'.$status->symbol().' warning:</>',
            DoctorStatus::NotVerifiable => '<fg=yellow>'.$status->symbol().' not verifiable:</>',
            DoctorStatus::Failed => '<fg=red>'.$status->symbol().' failed:</>',
        };
    }
}
