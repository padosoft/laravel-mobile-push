<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Receipts\ReceiptMarker;
use Padosoft\MobilePush\Laravel\Privacy\PrivacyModule;
use Padosoft\MobilePush\Laravel\Receipts\ReceiptsModule;

it('derives its own key from the application key, different from the index and encryption keys', function (): void {
    $ring = ReceiptsModule::keyRing(app());
    $other = PrivacyModule::indexKeyRing(app());

    expect($ring->versions())->toBe([1])
        ->and($ring->key(1))->not->toBe($other->key(1))
        ->and(strlen((string) $ring->key(1)))->toBe(32);
});

it('reads a rotated key ring from config: both versions verify, the current one signs', function (): void {
    config()->set('mobile-push.receipts.keys', [1 => base64_encode(random_bytes(32)), 2 => 'base64:'.base64_encode(random_bytes(32))]);
    config()->set('mobile-push.receipts.key_current', 2);

    $marker = new ReceiptMarker(ReceiptsModule::keyRing(app()));
    $token = $marker->issue('default', '0191a2b3-c4d5-4e6f-8a7b-9c0d1e2f3a4b', new DateTimeImmutable('+1 day'));

    expect(ReceiptMarker::versionOf($token))->toBe(2);
});

it('refuses weak or unusable keys with a clear message', function (array $config): void {
    config()->set('mobile-push.receipts', $config);

    expect(fn () => new ReceiptMarker(ReceiptsModule::keyRing(app())))->toThrow(ConfigurationException::class);
})->with([
    'too short' => [['keys' => [1 => base64_encode('short')]]],
    'not base64' => [['keys' => [1 => '***']]],
    'current missing' => [['keys' => [1 => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='], 'key_current' => 5]],
    'version above 255 (marker size)' => [['keys' => [300 => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']]],
]);

it('registers the route under the configured prefix, domain and middleware only when active', function (): void {
    config()->set('mobile-push.delivery.profile', 'durable');
    config()->set('mobile-push.receipts.enabled', true);
    config()->set('mobile-push.receipts.route', ['prefix' => 'api/push', 'domain' => 'push.example.test', 'middleware' => ['throttle:mobile-push-receipts', 'auth:sanctum']]);
    ReceiptsModule::boot(app());

    Route::getRoutes()->refreshNameLookups();
    $route = Route::getRoutes()->getByName(ReceiptsModule::ROUTE);

    expect($route?->uri())->toBe('api/push/receipts')
        ->and($route?->getDomain())->toBe('push.example.test')
        ->and($route?->gatherMiddleware())->toContain('auth:sanctum')
        ->and($route?->methods())->toBe(['POST']);
});

it('has safe defaults: off, no auth middleware, a throttle, a seven-day marker', function (): void {
    expect(ReceiptsModule::enabled())->toBeFalse()
        ->and(ReceiptsModule::active())->toBeFalse()
        ->and(ReceiptsModule::maxBatch())->toBe(20)
        ->and(ReceiptsModule::perMarkerPerMinute())->toBe(10);

    config()->set('mobile-push.delivery.profile', 'durable');
    config()->set('mobile-push.receipts.enabled', true);
    ReceiptsModule::boot(app());

    Route::getRoutes()->refreshNameLookups();

    expect(Route::getRoutes()->getByName(ReceiptsModule::ROUTE)?->uri())->toBe('mobile-push/receipts')
        ->and(Route::getRoutes()->getByName(ReceiptsModule::ROUTE)?->gatherMiddleware())->toBe(['throttle:mobile-push-receipts']);
});
