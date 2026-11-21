<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Runtime;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld;
use Padosoft\MobilePush\Tests\Support\H2Mock\MockServer;

/**
 * A Testbench application wired for the long-lived runtime stories: production environment and live mode (so pushes
 * really leave), the real transport against the mock server, APNs connections whose keys are generated at runtime.
 */
final class RuntimeWorld
{
    private function __construct(public readonly RedirectingTransport $transport) {}

    /** @param  array<string, mixed>  $config  extra mobile-push.* keys */
    public static function install(MockServer $server, array $config = [], ?CurlPoolRegistry $registry = null): self
    {
        $world = new self(new RedirectingTransport(new CurlTransport($registry ?? new CurlPoolRegistry), $server->baseUrl()));
        app()['env'] = 'production';
        app()->instance(Transport::class, $world->transport);
        config()->set('mobile-push.mode', 'live');
        config()->set('mobile-push.connections', []);
        foreach ($config as $key => $value) {
            config()->set('mobile-push.'.$key, $value);
        }
        SendingWorld::forgetSingletons();

        return $world;
    }

    /** An APNs connection config pointed at the mock server's CA, one distinct key per $keyId. @return array<string, mixed> */
    public static function apns(MockServer $server, string $keyId = 'KEY1111111', string $team = SendingWorld::TEAM, string $bundle = SendingWorld::BUNDLE): array
    {
        return SendingWorld::apns($keyId, $bundle, $team, extra: ['ca_bundle' => $server->caBundlePath(), 'timeout_ms' => 5000, 'connect_timeout_ms' => 2000]);
    }

    public static function token(int $i): string
    {
        return sprintf('%064x', $i + 1);
    }

    /** The database queue with its jobs table. */
    public static function databaseQueue(): void
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
        config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 300, 'after_commit' => false]);
        config()->set('mobile-push.queue.connection', 'database');
    }
}
