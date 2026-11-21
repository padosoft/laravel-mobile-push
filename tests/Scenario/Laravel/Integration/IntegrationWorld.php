<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Integration;

use Illuminate\Support\Facades\DB;
use Padosoft\MobilePush\Core\Credentials\CredentialCheck;
use Padosoft\MobilePush\Core\Policy\Badge\BadgeCounter;
use Padosoft\MobilePush\Core\Policy\Badge\BadgeResolver;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyCapGate;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyCounter;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyLimits;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Laravel\Health\ConnectionCredentialsSource;
use Padosoft\MobilePush\Laravel\Health\CredentialMonitor;
use Padosoft\MobilePush\Laravel\Health\HealthInspector;
use Padosoft\MobilePush\Laravel\Health\HealthSettings;
use Padosoft\MobilePush\Laravel\Health\MetricsRecorder;
use Padosoft\MobilePush\Laravel\Health\OpsAlerter;
use Padosoft\MobilePush\Laravel\Health\PrometheusExporter;
use Padosoft\MobilePush\Laravel\Integration\IntegrationModule;
use Padosoft\MobilePush\Laravel\Ledger\DatabaseLedger;
use Padosoft\MobilePush\Laravel\Ledger\LedgerModule;
use Padosoft\MobilePush\Laravel\Logging\LoggingModule;
use Padosoft\MobilePush\Laravel\Logging\SinkManager;
use Padosoft\MobilePush\Laravel\MobilePushServiceProvider;
use Padosoft\MobilePush\Laravel\Policies\PolicyGates;
use Padosoft\MobilePush\Core\Receipts\ReceiptMarker;
use Padosoft\MobilePush\Laravel\Receipts\ReceiptMarkerIssuer;
use Padosoft\MobilePush\Laravel\Receipts\ReceiptsModule;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld;

require_once dirname(__DIR__).'/Sending/SendingWorld.php';

/**
 * The whole package wired in one Testbench app: the real PushManager over a FakeTransport (SendingWorld), plus the
 * integration glue, the database log sink, the device registry, the ledger and receipts as each story needs.
 * Every token is a made-up test value; keys are generated at runtime.
 */
final class IntegrationWorld
{
    private function __construct(public readonly SendingWorld $send) {}

    /** @param  array<string, mixed>  $config  mobile-push.* keys (dotted, relative to mobile-push) */
    public static function install(array $config = [], string $environment = 'production'): self
    {
        $world = new self(SendingWorld::install($config, $environment));
        if (! in_array(IntegrationModule::class, MobilePushServiceProvider::MODULES, true)) {
            // Until the orchestrator lists the module in the provider, the stories register it themselves.
            IntegrationModule::register(app());
            IntegrationModule::boot(app());
        }
        self::forget();

        return $world;
    }

    /** Forget every singleton that captured config, so a config change made by a story is picked up. */
    public static function forget(): void
    {
        SendingWorld::forgetSingletons();
        foreach ([
            HealthSettings::class, MetricsRecorder::class, PrometheusExporter::class, HealthInspector::class,
            OpsAlerter::class, CredentialMonitor::class, ConnectionCredentialsSource::class, CredentialCheck::class,
            PolicyGates::class, FrequencyCapGate::class, FrequencyLimits::class, FrequencyCounter::class,
            BadgeResolver::class, BadgeCounter::class, ReceiptMarkerIssuer::class, ReceiptMarker::class,
            DatabaseLedger::class,
        ] as $abstract) {
            app()->forgetInstance($abstract);
        }
    }

    public function withDatabaseLog(): self
    {
        config()->set('mobile-push.log.sinks', ['database']);
        config()->set('mobile-push.log.database.mode', 'all');
        (require LoggingModule::migrationPath())->up();
        self::forget();

        return $this;
    }

    public function withDurableLedger(bool $receipts = false): self
    {
        config()->set('mobile-push.delivery.profile', 'durable');
        config()->set('mobile-push.receipts.enabled', $receipts);
        foreach (LedgerModule::migrationPaths() as $path) {
            (require $path)->up();
        }
        self::forget();
        if ($receipts) {
            ReceiptsModule::boot(app());
            app('router')->getRoutes()->refreshNameLookups();
        }

        return $this;
    }

    /** @return list<array<string, mixed>> every row of the send log */
    public static function logRows(): array
    {
        app(SinkManager::class)->flush();

        return array_map(static fn (object $row): array => (array) $row, DB::table('mobile_push_logs')->get()->all());
    }

    /** @return array<string, mixed> the decoded JSON body of a request */
    public static function body(Request $request): array
    {
        $json = json_decode($request->body, true, flags: JSON_THROW_ON_ERROR);

        return is_array($json) ? $json : [];
    }
}
