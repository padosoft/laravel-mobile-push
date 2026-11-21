<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Console;

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld;

/** Helpers for the console scenarios: run a command (non-interactive by default) and make test certificates. */
final class ConsoleWorld
{
    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: int, 1: string} exit code and output
     */
    public static function run(string $command, array $arguments = [], bool $interactive = false): array
    {
        if (! $interactive) {
            $arguments['--no-interaction'] = true;
        }
        $code = Artisan::call($command, $arguments);

        return [$code, Artisan::output()];
    }

    /** Makes the package cache a lock-capable store that is not "array" or "file", as a production redis would be. */
    public static function sharedCache(): void
    {
        Cache::extend('shared-test', static fn () => Cache::repository(new ArrayStore));
        config()->set('cache.stores.shared', ['driver' => 'shared-test']);
        config()->set('mobile-push.cache.store', 'shared');
    }

    /** @return array<string, mixed> the connections of the usual two-platform app */
    public static function both(): array
    {
        return ['connections' => ['ios' => SendingWorld::apns(), 'android' => SendingWorld::fcm()]];
    }

    /** @return array{0: string, 1: string} certificate PEM and private key PEM, valid for $days from now */
    public static function certificate(int $days): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($key === false) {
            throw new \RuntimeException('Cannot generate a test key.');
        }
        $csr = openssl_csr_new(['commonName' => 'Apple Push Services: com.example.shop'], $key);
        $cert = $csr === false ? false : openssl_csr_sign($csr, null, $key, $days);
        if ($cert === false) {
            throw new \RuntimeException('Cannot generate a test certificate.');
        }
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);

        return [(string) $certPem, (string) $keyPem];
    }

    /** A line of a PEM body: it must never appear in any output. */
    public static function canary(string $pem): string
    {
        $lines = array_values(array_filter(explode("\n", $pem), static fn (string $l): bool => $l !== '' && ! str_starts_with($l, '-----')));

        return $lines[1] ?? $lines[0];
    }
}
