<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Notification;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Laravel\Health\AlertKind;
use Padosoft\MobilePush\Laravel\Health\AlertOutcome;
use Padosoft\MobilePush\Laravel\Health\HealthSettings;
use Padosoft\MobilePush\Laravel\Health\MetricsRecorder;
use Padosoft\MobilePush\Laravel\Health\OpsAlerter;
use Padosoft\MobilePush\Tests\Support\FakeClock;

function alerter(FakeClock $clock, HealthSettings $settings, ?Repository $cache = null): OpsAlerter
{
    $cache ??= new Repository(new ArrayStore);

    return new OpsAlerter($settings, $clock, $cache, new Redactor, null, new MetricsRecorder($cache, $clock), static fn () => null, static fn () => null);
}

it('alerts once per key and cooldown, per kind and subject independently', function (): void {
    Notification::fake();
    $clock = new FakeClock;
    $a = alerter($clock, new HealthSettings(alertsEnabled: true, alertMail: ['ops@example.test'], alertCooldownSeconds: 100, mode: 'live'));

    expect($a->raise(AlertKind::LedgerBacklog, 'ledger', 't', 'b'))->toBe(AlertOutcome::Sent)
        ->and($a->raise(AlertKind::LedgerBacklog, 'ledger', 't', 'b'))->toBe(AlertOutcome::Suppressed)
        ->and($a->raise(AlertKind::LedgerBacklog, 'other', 't', 'b'))->toBe(AlertOutcome::Sent)
        ->and($a->raise(AlertKind::LogSinkFailing, 'ledger', 't', 'b'))->toBe(AlertOutcome::Sent);

    $clock->advance(99);
    expect($a->raise(AlertKind::LedgerBacklog, 'ledger', 't', 'b'))->toBe(AlertOutcome::Suppressed);
    $clock->advance(1);
    expect($a->raise(AlertKind::LedgerBacklog, 'ledger', 't', 'b'))->toBe(AlertOutcome::Sent);
});

it('does nothing when alerts are disabled', function (): void {
    Notification::fake();

    expect(alerter(new FakeClock, new HealthSettings(alertsEnabled: false))->raise(AlertKind::LedgerBacklog, 'x', 't', 'b'))->toBe(AlertOutcome::Disabled);
    Notification::assertNothingSent();
});
