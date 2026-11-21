<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Padosoft\MobilePush\Laravel\Health\ConnectionCredentialsSource;
use Padosoft\MobilePush\Laravel\Health\CredentialMonitor;
use Padosoft\MobilePush\Laravel\Health\HealthInspector;
use Padosoft\MobilePush\Laravel\Health\OpsAlert;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Console\Ops\OpsWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\HealthKit;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Health\World;

require_once __DIR__.'/OpsWorld.php';
require_once __DIR__.'/../../Health/HealthKit.php';

/*
 * What a monitoring system or a cron job sees: exit codes and JSON, never a request to a provider.
 */

/**
 * The ops world with a health setup whose certificate is valid until $days from now (negative: expired) and whose
 * clock is the health clock (the one the certificate dates were computed from).
 */
function opsHealth(int $days, bool $source = true): OpsWorld
{
    $world = OpsWorld::boot();
    $clock = World::build(app());
    $world->w->clock = $clock;
    if ($source) {
        $expires = $clock->now()->modify(($days >= 0 ? '+' : '').$days.' days');
        app()->instance(ConnectionCredentialsSource::class, HealthKit::certificateSource($expires, $clock->now()->modify('-300 days')));
    }
    foreach ([HealthInspector::class, CredentialMonitor::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    return $world;
}

beforeEach(function (): void {
    Notification::fake();
});

it('health exits 0 / 1 / 2 for an ok, warning and failed report and prints valid JSON', function (int $days, int $exit, string $status): void {
    $world = opsHealth($days);

    $report = $world->json('mobile-push:health');
    [$code] = $world->call('mobile-push:health');

    expect($code)->toBe($exit)
        ->and($report['status'])->toBe($status)
        ->and($report['exit_code'])->toBe($exit)
        ->and($report['liveness']['checks'])->not->toBeEmpty()
        ->and($report['readiness']['checks'])->not->toBeEmpty()
        ->and($report['live_probe'])->toContain('no request was sent');
    expect($world->w->transport->sentCount())->toBe(0);
})->with([
    'a certificate with 200 days left' => [200, 0, 'ok'],
    'a certificate expiring in 7 days' => [7, 1, 'warning'],
    'an expired certificate' => [-1, 2, 'failed'],
]);

it('health prints the two sections with each check, its status and the remedy', function (): void {
    $world = opsHealth(7);

    [$exit, $output] = $world->call('mobile-push:health');

    expect($exit)->toBe(1)
        ->and($output)->toContain('Liveness')->toContain('Readiness')->toContain('credentials.main')->toContain('warning')
        ->and($output)->toContain('No request was sent to any provider');
});

it('credentials:check exits 1 for a certificate about to expire, naming the connection and the days left', function (): void {
    $world = opsHealth(7);

    $json = $world->json('mobile-push:credentials:check', ['--no-alert' => true]);
    [$exit, $output] = $world->call('mobile-push:credentials:check', ['--no-alert' => true]);

    expect($exit)->toBe(1)
        ->and($json['status'])->toBe('warning')
        ->and($json['connections'][0]['connection'])->toBe('main')
        ->and($json['connections'][0]['days_remaining'])->toBe(7)
        ->and($output)->toContain('main')->toContain('7 more day(s)')
        ->and($world->w->transport->sentCount())->toBe(0);
    Notification::assertNothingSent();   // --no-alert

    // Without --no-alert the same finding raises one alert (the cooldown absorbs the next ones).
    $world->call('mobile-push:credentials:check');
    Notification::assertSentOnDemandTimes(OpsAlert::class, 1);
});

it('credentials:check exits 2 once the certificate has expired', function (): void {
    $world = opsHealth(-1);

    [$exit] = $world->call('mobile-push:credentials:check', ['--no-alert' => true]);

    expect($exit)->toBe(2);
});

it('credentials:check says "not verifiable" when no credentials source is available, and does not pretend', function (): void {
    $world = opsHealth(0, source: false);

    [$exit, $output] = $world->call('mobile-push:credentials:check');
    $json = $world->json('mobile-push:credentials:check');

    expect($exit)->toBe(0)
        ->and($output)->toContain('not verifiable')
        ->and($json['status'])->toBe('not_verifiable')
        ->and($json['connections'])->toBe([]);
});
