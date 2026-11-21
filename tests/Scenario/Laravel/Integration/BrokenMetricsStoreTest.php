<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Health\MetricsRecorder;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Integration\IntegrationWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;
use Psr\Clock\ClockInterface;

require_once __DIR__.'/IntegrationWorld.php';

/** A cache server that is down: every operation throws. */
final class DownCacheStore extends ArrayStore
{
    public int $calls = 0;

    public function get($key): mixed
    {
        $this->calls++;
        throw new RuntimeException('cache down');
    }

    public function put($key, $value, $seconds): bool
    {
        $this->calls++;
        throw new RuntimeException('cache down');
    }

    public function increment($key, $value = 1): int|bool
    {
        $this->calls++;
        throw new RuntimeException('cache down');
    }

    public function forget($key): bool
    {
        $this->calls++;
        throw new RuntimeException('cache down');
    }
}

/*
 * "A broken metrics store changes nothing": the same mixed batch (one accepted, one unregistered, one APNs 503) sent
 * with healthy metrics and with a metrics cache that throws on every call gives identical results and the same
 * number of requests.
 */
function mpMixedBatch(?DownCacheStore $down): array
{
    app('cache')->store()->flush();   // fresh guards: the 503 of a previous run paused the account
    $world = IntegrationWorld::install(['connections' => ['ios' => W::apns(), 'android' => W::fcm()]]);
    if ($down !== null) {
        app()->instance(MetricsRecorder::class, new MetricsRecorder(new Repository($down), app(ClockInterface::class)));
    }
    $world->send->answer = static fn (Request $r): ?Response => match (true) {
        str_contains($r->url, W::apnsToken('gone')) => new Response(410, [], '{"reason":"Unregistered","timestamp":1795168800000}'),
        str_contains($r->url, W::apnsToken('busy')) => new Response(503, [], '{"reason":"ServiceUnavailable"}'),
        default => null,
    };

    $results = W::all(MobilePush::to([
        ApnsDeviceToken::of(W::apnsToken('ok')),
        ApnsDeviceToken::of(W::apnsToken('gone')),
        ApnsDeviceToken::of(W::apnsToken('busy')),
        FcmToken::of('fake-registration-token-ok'),
    ])->send(PushMessage::make('Hi')));

    return [
        array_map(static fn ($r): string => $r->status->value.'/'.$r->reason->code->value.'/'.$r->destinationAction->value, $results),
        count($world->send->pushRequests()),
    ];
}

it('sends the same, and reports the same, when the metrics store is down', function () {
    [$healthy, $healthyRequests] = mpMixedBatch(null);
    $down = new DownCacheStore;
    [$broken, $brokenRequests] = mpMixedBatch($down);

    expect($broken)->toBe($healthy)
        ->and($brokenRequests)->toBe($healthyRequests)
        ->and($healthyRequests)->toBe(4)
        ->and($down->calls)->toBeGreaterThan(0)          // the metrics path really ran against the broken store
        ->and($healthy)->toContain('accepted/none/none', 'rejected/destination_unregistered/invalidate');
});
