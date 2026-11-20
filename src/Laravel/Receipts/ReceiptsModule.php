<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Receipts;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Ledger\ClientReceiptStore;
use Padosoft\MobilePush\Core\Ledger\DeliveryStore;
use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Privacy\PrivacyException;
use Padosoft\MobilePush\Core\Receipts\ReceiptMarker;
use Padosoft\MobilePush\Laravel\Ledger\LedgerModule;
use Padosoft\MobilePush\Laravel\Module;
use Psr\Clock\ClockInterface;

/**
 * Client receipts ("received" / "opened"). Requires the durable profile: it writes to the ledger.
 *
 * What it gives: a marker the send path embeds in individual pushes (ReceiptMarkerIssuer), an endpoint the app posts
 * to, two events and a funnel read. What the data means: the app REPORTED receiving or opening; a missing report is
 * "not observed", never "not delivered".
 *
 * Config (read with defaults):
 *  - mobile-push.receipts.enabled                 bool, false
 *  - mobile-push.receipts.marker_ttl              seconds, 604800 (7 days)
 *  - mobile-push.receipts.max_batch               reports per request, 20
 *  - mobile-push.receipts.route.prefix            'mobile-push'  (POST {prefix}/receipts)
 *  - mobile-push.receipts.route.domain            null
 *  - mobile-push.receipts.route.middleware        ['throttle:mobile-push-receipts']
 *  - mobile-push.receipts.rate_limit.per_ip       requests per minute per IP, 120 (0 = no limit)
 *  - mobile-push.receipts.rate_limit.per_marker   reports per minute per marker, 10 (0 = no limit)
 *  - mobile-push.receipts.keys / key_current      version(1-255) => base64 key (>= 32 bytes); default: derived
 *                                                 from the application key (HKDF), version 1
 */
final class ReceiptsModule implements Module
{
    public const LIMITER = 'mobile-push-receipts';

    public const ROUTE = 'mobile-push.receipts.store';

    public const INFO = 'padosoft/mobile-push receipt-key v1';

    public static function register(Application $app): void
    {
        $app->singleton(ReceiptMarker::class, static fn (Application $app): ReceiptMarker => new ReceiptMarker(self::keyRing($app)));
        $app->singleton(ReceiptMarkerIssuer::class, static fn (Application $app): ReceiptMarkerIssuer => new ReceiptMarkerIssuer(
            $app->make(ReceiptMarker::class),
            $app->make(ClockInterface::class),
            static fn (): bool => self::active(),
            self::int('mobile-push.receipts.marker_ttl', 604_800, 60),
        ));
        $app->singleton(ReceiptRecorder::class, static fn (Application $app): ReceiptRecorder => new ReceiptRecorder(
            $app->make(ReceiptMarker::class),
            $app->make(ClientReceiptStore::class),
            $app->make(ClockInterface::class),
            $app->make(Dispatcher::class),
            $app->make(DeliveryStore::class),
        ));
        $app->singleton(ReceiptFunnelReader::class, static fn (Application $app): ReceiptFunnelReader => new ReceiptFunnelReader($app->make(ClientReceiptStore::class)));
    }

    public static function boot(Application $app): void
    {
        if (! self::active()) {
            return;   // off, or the light profile: no routes, no limiter
        }

        RateLimiter::for(self::LIMITER, static function (Request $request): Limit {
            $max = self::int('mobile-push.receipts.rate_limit.per_ip', 120, 0);

            return $max === 0 ? Limit::none() : Limit::perMinute($max)->by('mobile-push:receipts:ip:'.$request->ip());
        });

        if ($app->routesAreCached()) {
            return;
        }

        $middleware = config('mobile-push.receipts.route.middleware', ['throttle:'.self::LIMITER]);
        $domain = config('mobile-push.receipts.route.domain');
        $prefix = config('mobile-push.receipts.route.prefix', 'mobile-push');

        $route = Route::middleware(is_array($middleware) ? array_values(array_filter($middleware, 'is_string')) : [])
            ->prefix(is_string($prefix) ? $prefix : 'mobile-push');
        if (is_string($domain) && $domain !== '') {
            $route = $route->domain($domain);
        }
        $route->group(static function (): void {
            Route::post('receipts', ReceiptsController::class)->name(self::ROUTE);
        });
    }

    public static function enabled(): bool
    {
        return config('mobile-push.receipts.enabled', false) === true;
    }

    /** Receipts work only when enabled AND the durable profile is on. */
    public static function active(): bool
    {
        return self::enabled() && LedgerModule::durable();
    }

    public static function maxBatch(): int
    {
        return self::int('mobile-push.receipts.max_batch', 20, 1);
    }

    public static function perMarkerPerMinute(): int
    {
        return self::int('mobile-push.receipts.rate_limit.per_marker', 10, 0);
    }

    public static function keyRing(Application $app): KeyRing
    {
        $configured = config('mobile-push.receipts.keys', []);
        $current = config('mobile-push.receipts.key_current', 0);
        $current = is_numeric($current) ? (int) $current : 0;

        $keys = [];
        if (is_array($configured)) {
            foreach ($configured as $version => $encoded) {
                $remedy = "Set mobile-push.receipts.keys[{$version}] to a base64 string of at least ".KeyRing::MIN_KEY_BYTES.' random bytes.';
                if (! is_string($encoded) || (! is_int($version) && ! ctype_digit($version))) {
                    throw new ConfigurationException($remedy);
                }
                $raw = base64_decode(str_starts_with($encoded, 'base64:') ? substr($encoded, 7) : $encoded, true);
                if ($raw === false || strlen($raw) < KeyRing::MIN_KEY_BYTES) {
                    throw new ConfigurationException($remedy);
                }
                $keys[(int) $version] = $raw;
            }
        }

        if ($keys === []) {
            $appKey = config('app.key');
            if (! is_string($appKey) || $appKey === '') {
                throw new ConfigurationException('No application key to derive the receipt key from: run `php artisan key:generate`, or set mobile-push.receipts.keys.');
            }
            $raw = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;
            if ($raw === false || $raw === '') {
                throw new ConfigurationException('The application key is not valid: run `php artisan key:generate`, or set mobile-push.receipts.keys.');
            }
            $keys = [1 => hash_hkdf('sha256', $raw, 32, self::INFO)];
        }

        try {
            return new KeyRing($keys, $current);
        } catch (PrivacyException) {
            throw new ConfigurationException('mobile-push.receipts.keys or key_current is invalid: versions must be integers 1-255 and the current version must exist in the map.');
        }
    }

    private static function int(string $key, int $default, int $min): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? max($min, (int) $value) : $default;
    }
}
