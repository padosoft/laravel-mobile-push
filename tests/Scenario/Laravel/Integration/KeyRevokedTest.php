<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Health\HealthInspector;
use Padosoft\MobilePush\Laravel\Health\HealthStatus;
use Padosoft\MobilePush\Laravel\Health\OpsAlert;
use Padosoft\MobilePush\Laravel\Health\OpsAlerter;
use Padosoft\MobilePush\Laravel\Health\PrometheusExporter;
use Padosoft\MobilePush\Laravel\Sending\Events\CircuitOpened;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Integration\IntegrationWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

require_once __DIR__.'/IntegrationWorld.php';

/*
 * "The key was revoked": Apple answers 403 InvalidProviderToken to every push. After the configured number of
 * credential failures the authentication breaker opens: ONE circuit_opened alert goes to the ops mailbox, the
 * Prometheus gauge shows the open circuit, and health readiness says so; later sends do not reach Apple.
 */
it('a revoked key opens the breaker once: one alert, an open-circuit metric, readiness degraded, no more requests', function () {
    Notification::fake();
    $world = IntegrationWorld::install([
        'connections' => ['ios' => W::apns()],
        'guards.auth.threshold' => 3,
        'alerts.enabled' => true,
        'alerts.mail' => ['ops@example.com'],
    ]);
    $world->send->answer = static fn (Request $r): Response => new Response(403, ['apns-id' => '11111111-2222-4333-8444-555555555555'], '{"reason":"InvalidProviderToken"}');

    $outcomes = [];
    for ($i = 1; $i <= 6; $i++) {
        $result = W::all(MobilePush::connection('ios')->to(ApnsDeviceToken::of(W::apnsToken('phone-'.$i)))->send(PushMessage::make('Hi')))[0];
        $outcomes[] = $result->status->value.'/'.$result->reason->code->value;
    }
    $requestsWhenOpen = count($world->send->pushRequests());

    $breakerOpened = 'failed/'.ReasonCode::AuthTokenInvalid->value;
    $circuitOpen = 'skipped/'.ReasonCode::CircuitOpen->value;
    expect($outcomes)->toBe([$breakerOpened, $breakerOpened, $breakerOpened, $circuitOpen, $circuitOpen, $circuitOpen])
        ->and($requestsWhenOpen)->toBe(3)                                        // counted by the transport, not the sender
        ->and($world->send->eventsOf(CircuitOpened::class))->toHaveCount(1);

    // One alert to the ops mailbox, for the open circuit, even though three more sends hit it.
    Notification::assertSentOnDemandTimes(OpsAlert::class, 1);
    Notification::assertSentOnDemand(OpsAlert::class, static fn (OpsAlert $alert, array $channels, object $notifiable): bool => $notifiable->routeNotificationFor('mail') === ['ops@example.com']);

    // The metric shows the open circuit; health readiness reports it as failed and names the guard.
    $text = app(PrometheusExporter::class)->render();
    $guards = array_values(array_filter(app(HealthInspector::class)->inspect()->readiness, static fn ($c): bool => $c->name === 'guards'))[0];
    expect($text)->toContain('mobile_push_circuit_open{connection="ios",environment="production",guard="auth",provider="apns"} 1')
        ->and($text)->toContain('mobile_push_guard_transitions_total{connection="ios",environment="production",guard="auth",provider="apns",transition="opened"} 1')
        ->and($guards->status)->toBe(HealthStatus::Failed)
        ->and($guards->message)->toContain('ios/auth');

    // A later evaluation of the periodic alerts does not repeat it (cooldown).
    app(OpsAlerter::class)->evaluate();
    Notification::assertSentOnDemandTimes(OpsAlert::class, 1);
});
