<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Ops;

use Padosoft\MobilePush\Core\Credentials\Finding;
use Padosoft\MobilePush\Core\Credentials\Severity;
use Padosoft\MobilePush\Laravel\Health\CredentialFindings;
use Padosoft\MobilePush\Laravel\Health\CredentialMonitor;

/**
 * Inspects the credentials of every configured connection (certificate and key validity, service account) without
 * contacting any provider, optionally raising the expiry alerts. Schedulable.
 *
 * Exit code: 0 ok or not verifiable, 1 warnings (for instance a certificate close to expiry), 2 errors.
 */
final class CredentialsCheckCommand extends OpsCommand
{
    protected $signature = 'mobile-push:credentials:check
        {--no-alert : Only report, do not send expiry alerts}
        {--json : Print the findings as JSON}';

    protected $description = 'Check credential expiry and validity of every connection (exit 0 ok, 1 warnings, 2 errors)';

    public function handle(): int
    {
        $monitor = $this->laravel->make(CredentialMonitor::class);

        if (! $monitor->available()) {
            $message = 'Credentials are not verifiable from here: no credentials source is bound, so the package cannot enumerate your connections. This is not a pass. Bind a Padosoft\MobilePush\Laravel\Health\ConnectionCredentialsSource to enable the check.';
            if ($this->wantsJson()) {
                $this->emitJson(['status' => 'not_verifiable', 'exit_code' => 0, 'message' => $message, 'connections' => []]);
            } else {
                $this->components->warn($message);
            }

            return self::SUCCESS;
        }

        $all = $monitor->check(! (bool) $this->option('no-alert'));
        $exit = 0;
        $connections = [];
        foreach ($all as $found) {
            $exit = max($exit, match ($found->result->worst()) {
                Severity::Error => 2,
                Severity::Warning => 1,
                default => 0,
            });
            $connections[] = self::describe($found);
        }

        if ($this->wantsJson()) {
            $this->emitJson(['status' => ['ok', 'warning', 'error'][$exit], 'exit_code' => $exit, 'connections' => $connections]);

            return $exit;
        }

        if ($all === []) {
            $this->components->info('The credentials source knows no connection: nothing to check.');

            return self::SUCCESS;
        }

        foreach ($connections as $connection) {
            $days = $connection['days_remaining'];
            $this->line(sprintf(
                '<options=bold>%s</> (%s, %s, %s) - %s%s',
                $connection['connection'], $connection['provider'], $connection['app_id'], $connection['environment'],
                $connection['status'],
                $days === null ? '' : sprintf(', certificate valid for %d more day(s)', $days),
            ));
            $this->table(['Severity', 'Code', 'Finding and remedy'], array_map(
                static fn (array $f): array => [$f['severity'], $f['code'], $f['message']],
                $connection['findings'],
            ));
            if ($connection['alerts'] !== []) {
                $this->line('  alerts: '.implode(', ', $connection['alerts']));
            }
        }

        return $exit;
    }

    /** @return array{connection: string, provider: string, app_id: string, environment: string, status: string, days_remaining: ?int, error: ?string, findings: list<array{severity: string, code: string, message: string}>, alerts: list<string>} */
    private static function describe(CredentialFindings $found): array
    {
        return [
            'connection' => $found->binding->name,
            'provider' => $found->binding->provider->value,
            'app_id' => $found->binding->appId,
            'environment' => $found->binding->environment->value,
            'status' => $found->result->worst()->value,
            'days_remaining' => $found->daysRemaining,
            'error' => $found->error === null ? null : 'unreadable',
            'findings' => array_map(
                static fn (Finding $f): array => ['severity' => $f->severity->value, 'code' => $f->code, 'message' => $f->message],
                $found->result->findings,
            ),
            'alerts' => array_map(static fn ($a): string => $a->value, $found->alerts),
        ];
    }
}
