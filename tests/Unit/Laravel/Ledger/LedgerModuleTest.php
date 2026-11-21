<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Ledger\DeliveryStore;
use Padosoft\MobilePush\Core\Ledger\EffectIntent;
use Padosoft\MobilePush\Core\Ledger\EffectStore;
use Padosoft\MobilePush\Core\Ledger\Ledger;
use Padosoft\MobilePush\Core\Ledger\MessageVault;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Retry\UnknownOutcomeRetry;
use Padosoft\MobilePush\Laravel\Ledger\ClaimStrategy;
use Padosoft\MobilePush\Laravel\Ledger\DatabaseLedger;
use Padosoft\MobilePush\Laravel\Ledger\LedgerModule;

it('binds the ledger contracts to one database ledger', function (): void {
    $ledger = app(Ledger::class);

    expect($ledger)->toBeInstanceOf(DatabaseLedger::class)
        ->and(app(EffectStore::class))->toBe($ledger)
        ->and(app(DeliveryStore::class))->toBe($ledger)
        ->and(app(MessageVault::class))->toBeInstanceOf(MessageVault::class)
        ->and($ledger->claimStrategy())->toBe(ClaimStrategy::Optimistic); // SQLite
});

it('loads the ledger migrations only in the durable profile, and only when migrations.autoload is on', function (): void {
    $ledgerPaths = static fn (): array => array_values(array_intersect(app('migrator')->paths(), LedgerModule::migrationPaths()));

    expect(config('mobile-push.delivery.profile', 'light'))->toBe('light')
        ->and($ledgerPaths())->toBe([]);

    config()->set('mobile-push.delivery.profile', 'durable');
    LedgerModule::boot(app());
    expect($ledgerPaths())->toBe([]);   // published, not auto-loaded, by default

    config()->set('mobile-push.migrations.autoload', true);
    LedgerModule::boot(app());

    expect($ledgerPaths())->toBe(LedgerModule::migrationPaths());
});

it('publishes both migration stubs under its own tag', function (): void {
    $published = ServiceProvider::pathsToPublish(null, LedgerModule::PUBLISH_TAG);

    expect(array_keys($published))->toBe(LedgerModule::migrationPaths())
        ->and(array_values($published))->each->toContain('_mobile_push_ledger_');
    foreach (LedgerModule::migrationPaths() as $path) {
        expect(is_file($path))->toBeTrue();
    }
});

it('reads its settings from config with defaults', function (): void {
    expect(LedgerModule::settings(app())->leaseSeconds)->toBe(120)
        ->and(LedgerModule::settings(app())->retry->unknownOutcome)->toBe(UnknownOutcomeRetry::Replaceable);

    config()->set('mobile-push.ledger.lease_seconds', 30);
    config()->set('mobile-push.unknown_outcome.retry', 'never');

    expect(LedgerModule::settings(app())->leaseSeconds)->toBe(30)
        ->and(LedgerModule::settings(app())->retry->unknownOutcome)->toBe(UnknownOutcomeRetry::Never);
});

it('rejects an unknown claim strategy before anything runs', function (): void {
    config()->set('mobile-push.ledger.claim_strategy', 'yolo');
    app()->forgetInstance(DatabaseLedger::class);

    expect(fn () => app(Ledger::class))->toThrow(ConfigurationException::class);
});

it('honours custom table names and connection', function (): void {
    config()->set('database.connections.ledger_db', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('mobile-push.ledger.connection', 'ledger_db');
    config()->set('mobile-push.ledger.tables.effects', 'push_effects');
    config()->set('mobile-push.ledger.tables.deliveries', 'push_deliveries');
    foreach (LedgerModule::migrationPaths() as $path) {
        (require $path)->up();
    }
    app()->forgetInstance(DatabaseLedger::class);

    $ledger = app(Ledger::class);
    $ledger->recordIntent(
        new EffectIntent(new BindingRef('default', 'main', Provider::Fcm, 'proj', Environment::Production), PushMessage::make('t', 'b')),
        [FcmTopic::of('news')],
    );

    expect(DB::connection('ledger_db')->table('push_deliveries')->count())->toBe(1)
        ->and(DB::connection('ledger_db')->table('push_effects')->count())->toBe(1);
});
