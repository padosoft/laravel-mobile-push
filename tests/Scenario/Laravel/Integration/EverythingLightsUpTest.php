<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Health\CredentialMonitor;
use Padosoft\MobilePush\Laravel\Health\HealthInspector;
use Padosoft\MobilePush\Laravel\Health\HealthStatus;
use Padosoft\MobilePush\Laravel\Health\PrometheusExporter;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Integration\IntegrationWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\Customer;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

require_once __DIR__.'/IntegrationWorld.php';

/*
 * "One real send lights up everything": a user with an iPhone, the device registry, the database send log,
 * metrics and health all on. One MobilePush::to($user)->send() must mark the device sent, write one log row without
 * the token, count the result with bounded labels only, and leave health ready with real credential findings.
 */
it('one real send marks the device, logs once without the token, counts the result and keeps health ready', function () {
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns()]])->withDatabaseLog();
    $world->send->withDevices();
    $user = Customer::withId(7);
    $token = W::apnsToken('iphone-of-7');
    $world->send->register(MobilePush::binding('ios'), ApnsDeviceToken::of($token), $user);

    $results = W::all(MobilePush::to($user)->send(PushMessage::make('Order shipped')));

    // The provider got one request; the device row knows it was sent.
    expect($results)->toHaveCount(1)
        ->and($results[0]->status)->toBe(SendStatus::Accepted)
        ->and($world->send->requestsTo('api.push.apple.com'))->toHaveCount(1)
        ->and(PushDevice::query()->whereNotNull('last_sent_at')->count())->toBe(1);

    // One log row, without the token or the bearer.
    $rows = IntegrationWorld::logRows();
    $dump = json_encode($rows, JSON_THROW_ON_ERROR);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['status'])->toBe('accepted')
        ->and($dump)->not->toContain($token)
        ->and($dump)->not->toContain(substr($world->send->requestsTo('api.push.apple.com')[0]->headers['authorization'], 7));

    // Prometheus: the result counter with only bounded labels (no token, no user, no device id).
    $text = app(PrometheusExporter::class)->render();
    preg_match('/^mobile_push_results_total\{([^}]*)\} 1$/m', $text, $line);
    expect($line)->not->toBe([]);
    preg_match_all('/(\w+)="/', $line[1], $labels);
    expect($labels[1])->toBe(['class', 'connection', 'environment', 'provider', 'reason', 'status'])
        ->and($line[1])->toContain('status="accepted"')
        ->and($text)->not->toContain($token)->not->toContain('7"');

    // Health: readiness ok, with a real finding for the connection's .p8 key (not "not verifiable").
    $report = app(HealthInspector::class)->inspect();
    $credentials = array_values(array_filter($report->readiness, static fn ($c): bool => str_starts_with($c->name, 'credentials')));
    expect($report->readinessStatus())->toBe(HealthStatus::Ok)
        ->and(array_map(static fn ($c): string => $c->name, $credentials))->toBe(['credentials.ios'])
        ->and($credentials[0]->status)->toBe(HealthStatus::Ok)
        ->and(app(CredentialMonitor::class)->available())->toBeTrue()
        ->and(app(CredentialMonitor::class)->check(false)[0]->result->codes())->not->toBe([]);
});
