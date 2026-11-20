<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Privacy;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Privacy\PrivacyException;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Laravel\Module;

/**
 * Privacy primitives from config. TokenIndex (HMAC lookup) and TokenCipher (encryption at rest) get two independent
 * key rings; KeyRing itself is deliberately not bound.
 *
 * Config (all optional):
 *  - mobile-push.privacy.index_keys / index_key_current
 *  - mobile-push.privacy.encryption_keys / encryption_key_current
 * Each `*_keys` is a map version(int) => base64 key (optional `base64:` prefix, at least 32 bytes decoded). When it is
 * empty, version 1 is derived from the application key with HKDF-SHA256 and a fixed per-purpose `info`.
 */
final class PrivacyModule implements Module
{
    public const INDEX_INFO = 'padosoft/mobile-push index-key v1';

    public const ENCRYPTION_INFO = 'padosoft/mobile-push encryption-key v1';

    public static function register(Application $app): void
    {
        $app->singleton(TokenIndex::class, static fn (Application $app): TokenIndex => new TokenIndex(self::indexKeyRing($app)));
        $app->singleton(TokenCipher::class, static fn (Application $app): TokenCipher => new TokenCipher(self::encryptionKeyRing($app)));
        $app->singleton(Redactor::class, static fn (): Redactor => new Redactor);
    }

    public static function boot(Application $app): void {}

    public static function indexKeyRing(Application $app): KeyRing
    {
        return self::ring($app, 'index_keys', 'index_key_current', self::INDEX_INFO);
    }

    public static function encryptionKeyRing(Application $app): KeyRing
    {
        return self::ring($app, 'encryption_keys', 'encryption_key_current', self::ENCRYPTION_INFO);
    }

    private static function ring(Application $app, string $keysKey, string $currentKey, string $info): KeyRing
    {
        $configured = self::config($app)->get('mobile-push.privacy.'.$keysKey, []);
        $current = self::config($app)->get('mobile-push.privacy.'.$currentKey, 0);
        $current = is_numeric($current) ? (int) $current : 0;

        $keys = [];
        if (is_array($configured)) {
            foreach ($configured as $version => $encoded) {
                if (! is_int($version) && ! (is_string($version) && ctype_digit($version))) {
                    throw new ConfigurationException("mobile-push.privacy.{$keysKey} must be a map of integer version => base64 key.");
                }
                $keys[(int) $version] = self::decode($keysKey, (int) $version, $encoded);
            }
        }

        if ($keys === []) {
            $keys = [1 => self::derive($app, $keysKey, $info)];
        }

        try {
            return new KeyRing($keys, $current);
        } catch (PrivacyException) {
            throw new ConfigurationException("mobile-push.privacy.{$keysKey} or {$currentKey} is invalid: versions must be integers 1-9999 and the current version must exist in the map.");
        }
    }

    private static function config(Application $app): Repository
    {
        $config = $app->make('config');

        return $config;
    }

    private static function decode(string $keysKey, int $version, mixed $encoded): string
    {
        $remedy = "Set mobile-push.privacy.{$keysKey}[{$version}] to a base64 string of at least ".KeyRing::MIN_KEY_BYTES.' random bytes, e.g. base64_encode(random_bytes(32)).';

        if (! is_string($encoded)) {
            throw new ConfigurationException($remedy);
        }
        $raw = base64_decode(str_starts_with($encoded, 'base64:') ? substr($encoded, 7) : $encoded, true);
        if ($raw === false || strlen($raw) < KeyRing::MIN_KEY_BYTES) {
            throw new ConfigurationException($remedy);
        }

        return $raw;
    }

    private static function derive(Application $app, string $keysKey, string $info): string
    {
        $appKey = self::config($app)->get('app.key');
        if (! is_string($appKey) || $appKey === '') {
            throw new ConfigurationException("No application key to derive the default key from: run `php artisan key:generate`, or set mobile-push.privacy.{$keysKey}.");
        }

        $raw = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;
        if ($raw === false || $raw === '') {
            throw new ConfigurationException("The application key is not valid: run `php artisan key:generate`, or set mobile-push.privacy.{$keysKey}.");
        }

        return hash_hkdf('sha256', $raw, 32, $info);
    }
}
