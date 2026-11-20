<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Ledger;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Ledger\ClientReceiptStore;
use Padosoft\MobilePush\Core\Ledger\DeliveryStore;
use Padosoft\MobilePush\Core\Ledger\EffectStore;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Core\Ledger\LedgerSettings;
use Padosoft\MobilePush\Core\Ledger\MessageVault;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;
use Padosoft\MobilePush\Core\Retry\UnknownOutcomeRetry;
use Padosoft\MobilePush\Laravel\Module;
use Padosoft\MobilePush\Laravel\Receipts\ReceiptMarkerIssuer;
use Psr\Clock\ClockInterface;

/**
 * Durable effects ledger. The bindings always exist (resolving them is cheap and lazy); the migrations are loaded
 * only when mobile-push.delivery.profile is "durable". The light profile never touches the ledger.
 *
 * Config (read with defaults): mobile-push.delivery.profile, mobile-push.ledger.{connection, tables.effects,
 * tables.deliveries, lease_seconds, dedupe_seconds, chunk_size, claim_strategy}. A RetryPolicy bound in the
 * container wins over mobile-push.retry.{max_attempts, max_elapsed} and mobile-push.unknown_outcome.retry.
 */
final class LedgerModule implements Module
{
    public const PUBLISH_TAG = 'mobile-push-migrations-ledger';

    public const MIGRATIONS = [
        '2026_11_20_000010_create_mobile_push_ledger_effects_table.php',
        '2026_11_20_000011_create_mobile_push_ledger_deliveries_table.php',
        '2026_11_20_000012_create_mobile_push_ledger_receipt_columns.php',
    ];

    public static function register(Application $app): void
    {
        $app->singleton(DatabaseLedger::class, static fn (Application $app): DatabaseLedger => new DatabaseLedger(
            $app->make('db'),
            $app->make(TokenIndex::class),
            $app->make(TokenCipher::class),
            $app->make(ClockInterface::class),
            self::settings($app),
            self::stringConfig('mobile-push.ledger.connection', null),
            self::stringConfig('mobile-push.ledger.tables.effects', 'mobile_push_effects') ?? 'mobile_push_effects',
            self::stringConfig('mobile-push.ledger.tables.deliveries', 'mobile_push_deliveries') ?? 'mobile_push_deliveries',
            self::strategy(),
        ));
        $app->alias(DatabaseLedger::class, Ledger::class);
        $app->alias(DatabaseLedger::class, EffectStore::class);
        $app->alias(DatabaseLedger::class, DeliveryStore::class);
        $app->alias(DatabaseLedger::class, ClientReceiptStore::class);
        $app->singleton(MessageVault::class, static fn (Application $app): MessageVault => new MessageVault($app->make(TokenCipher::class)));
    }

    public static function boot(Application $app): void
    {
        $paths = self::migrationPaths();

        if (self::durable() && $app->bound('migrator')) {
            foreach ($paths as $path) {
                $app->make('migrator')->path($path);
            }
        }

        if ($app->runningInConsole()) {
            $stamp = date('Y_m_d_His');
            $publish = [];
            foreach ($paths as $i => $path) {
                $publish[$path] = $app->databasePath('migrations/'.$stamp.'_'.($i + 10).'_'.substr(basename($path), 18));
            }

            (new class($app) extends ServiceProvider
            {
                /** @param  array<string, string>  $paths */
                public function publishTagged(array $paths, string $tag): void
                {
                    $this->publishes($paths, $tag);
                }
            })->publishTagged($publish, self::PUBLISH_TAG);
        }
    }

    public static function durable(): bool
    {
        return config('mobile-push.delivery.profile', 'light') === 'durable';
    }

    /** @return list<string> */
    public static function migrationPaths(): array
    {
        return array_map(static fn (string $file): string => dirname(__DIR__, 3).'/database/migrations/'.$file, self::MIGRATIONS);
    }

    public static function settings(Application $app): LedgerSettings
    {
        return new LedgerSettings(
            self::intConfig('mobile-push.ledger.lease_seconds', 120),
            self::intConfig('mobile-push.ledger.dedupe_seconds', 86_400),
            self::intConfig('mobile-push.ledger.chunk_size', 500),
            self::retryPolicy($app),
            $app->bound(ReceiptMarkerIssuer::class)
                ? static fn (string $messageId): bool => $app->make(ReceiptMarkerIssuer::class)->wasIssued($messageId)
                : null,
        );
    }

    private static function retryPolicy(Application $app): RetryPolicy
    {
        if ($app->bound(RetryPolicy::class)) {
            return $app->make(RetryPolicy::class);
        }

        $unknown = UnknownOutcomeRetry::tryFrom(self::stringConfig('mobile-push.unknown_outcome.retry', 'replaceable') ?? 'replaceable')
            ?? throw new ConfigurationException('mobile-push.unknown_outcome.retry must be one of: never, replaceable, always.');

        return new RetryPolicy(
            maxAttempts: self::intConfig('mobile-push.retry.max_attempts', 5),
            maxElapsedSeconds: self::intConfig('mobile-push.retry.max_elapsed', 3600),
            unknownOutcome: $unknown,
        );
    }

    private static function strategy(): ClaimStrategy
    {
        return ClaimStrategy::tryFrom(self::stringConfig('mobile-push.ledger.claim_strategy', 'auto') ?? 'auto')
            ?? throw new ConfigurationException('mobile-push.ledger.claim_strategy must be one of: auto, skip_locked, optimistic.');
    }

    private static function stringConfig(string $key, ?string $default): ?string
    {
        $value = config($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
