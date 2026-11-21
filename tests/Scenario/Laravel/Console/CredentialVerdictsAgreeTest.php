<?php

declare(strict_types=1);

use Padosoft\MobilePush\Tests\Scenario\Laravel\Console\ConsoleWorld as C;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

/*
 * One source of truth for credentials: doctor, connections, health and credentials:check read the same facts (what
 * the push manager inspected while resolving the connection) through the same check (credentials.warn_days), so an
 * expiring certificate is judged the same way by all four.
 */

/** @return array<string, mixed> */
function verdictJson(string $command): array
{
    [, $out] = C::run($command, ['--json' => true, ...($command === 'mobile-push:credentials:check' ? ['--no-alert' => true] : [])]);
    $json = json_decode(trim($out), true, flags: JSON_THROW_ON_ERROR);

    return is_array($json) ? $json : [];
}

it('judges a certificate that expires in N days the same way in all four commands', function (int $days, int $warnDays, string $expected): void {
    $world = W::install([]);
    [$cert, $key] = C::certificate($days);
    $world->clock->set(gmdate('c', time() + 60));
    config()->set('mobile-push.credentials.warn_days', $warnDays);
    config()->set('mobile-push.connections', ['ios' => [
        'driver' => 'apns', 'auth' => 'certificate', 'team_id' => W::TEAM, 'bundle_id' => W::BUNDLE, 'environment' => 'production',
        'certificate' => ['inline' => $cert], 'certificate_key' => ['inline' => $key],
    ]]);
    W::forgetSingletons();
    foreach ([\Padosoft\MobilePush\Laravel\Health\HealthSettings::class, \Padosoft\MobilePush\Laravel\Health\HealthInspector::class, \Padosoft\MobilePush\Laravel\Health\CredentialMonitor::class, \Padosoft\MobilePush\Core\Credentials\CredentialCheck::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $doctor = array_values(array_filter(verdictJson('mobile-push:doctor')['checks'], static fn (array $c): bool => $c['section'] === 'Connection "ios"'));
    array_pop($doctor); // the summary line (provider, app, generation), not a credential finding
    $connections = verdictJson('mobile-push:connections')['connections'][0];
    $health = array_values(array_filter(verdictJson('mobile-push:health')['readiness']['checks'], static fn (array $c): bool => $c['name'] === 'credentials.ios'))[0];
    $check = verdictJson('mobile-push:credentials:check')['connections'][0];
    $expiry = array_values(array_filter($check['findings'], static fn (array $f): bool => str_starts_with($f['code'], 'apns.certificate.') && str_contains($f['message'], 'day')))[0];

    expect($expiry['severity'])->toBe($expected)
        // doctor: the same findings, one check each, with the same severities
        ->and(array_column($doctor, 'status'))->toBe(array_column($check['findings'], 'severity'))
        ->and(array_map(static fn (array $c): string => strtok((string) $c['label'], '.'), $doctor))->toBe(array_map(static fn (array $f): string => strtok($f['message'], '.'), $check['findings']))
        // health: the same verdict for the connection
        ->and($health['status'])->toBe($check['status'])
        // connections: the same days left
        ->and($connections['days_left'])->toBe($check['days_remaining'])
        ->and($world->transport->sentCount())->toBe(0);
})->with([
    // With the old console-only check (30-day default window) doctor and connections said "expiring" here.
    'outside the warning window' => [20, 14, 'ok'],
    'inside the warning window' => [10, 14, 'warning'],
]);
