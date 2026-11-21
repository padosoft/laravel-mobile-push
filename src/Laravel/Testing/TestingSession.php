<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

use Illuminate\Contracts\Foundation\Application;
use Padosoft\MobilePush\Core\Driver\GateContext;
use Padosoft\MobilePush\Core\Driver\Sender;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Guard\Guards;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Policy\LiveSettings;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Laravel\Contracts\QueuedSender;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Sending\ConnectionCache;
use Padosoft\MobilePush\Laravel\Sending\ConnectionFactory;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Padosoft\MobilePush\Laravel\Sending\ResolvedConnection;
use Padosoft\MobilePush\Laravel\Sending\Senders;
use Padosoft\MobilePush\Laravel\Sending\SendingSettings;

/**
 * Turns the fakes on and off for one application. Nothing is swapped until fake() or fakeTransport() is called, and
 * reset() puts everything back (the application environment, the transport, the queue binding, the connections).
 *
 * While a fake is active the application environment reads as "production" to the send pipeline: that is what makes
 * the `auto` execution mode live and lets every destination through the safe-mode guard (outside production a
 * destination must be on a test-device allowlist). The real environment is saved and restored.
 */
final class TestingSession
{
    private readonly TestingState $state;

    public function __construct(private readonly Application $app)
    {
        $this->state = new TestingState;
    }

    /** @throws FakeNotAllowedException outside the testing environment unless explicitly allowed */
    public function fake(bool $allowOutsideTesting = false): PushFake
    {
        $this->guard($allowOutsideTesting);
        $this->reset();
        $this->goLive();

        $fake = new PushFake;
        $switch = $this->switchable();
        $manager = $this->app->make(PushManager::class);
        $switch->route(new AcceptingTransport);

        if (! isset($this->state->gated[$manager])) {
            $this->state->gated[$manager] = true;
            $manager->eligibleUsing(function (mixed $context, mixed $items): array {
                /** @var GateContext $context */
                /** @var list<SendItem> $items */
                return $this->state->intent?->capture($context, $items) ?? [];
            }, true);
        }
        $this->state->intent = $fake;
        $this->seed($manager, $fake);

        $this->app->instance(QueuedSender::class, new FakeQueuedSender($fake));
        $this->state->queuedSwapped = true;

        return $fake;
    }

    /** @throws FakeNotAllowedException outside the testing environment unless explicitly allowed */
    public function fakeTransport(bool $allowOutsideTesting = false): FakeTransportBuilder
    {
        $this->guard($allowOutsideTesting);
        $this->reset();
        $this->goLive();

        $this->provideConnections(TestConnections::wire());

        $builder = new FakeTransportBuilder;
        $this->switchable()->route($builder->transport());
        $this->state->wire = $builder;

        return $builder;
    }

    public function intent(): ?PushFake
    {
        return $this->state->intent;
    }

    public function wire(): ?FakeTransportBuilder
    {
        return $this->state->wire;
    }

    public function active(): bool
    {
        return $this->state->active();
    }

    /** Put everything back. Safe to call when nothing is active. */
    public function reset(): void
    {
        if (! $this->state->active() && $this->state->realEnvironment === null) {
            return;
        }

        $this->state->intent?->deactivate();
        $this->unseed();
        if ($this->state->queuedSwapped) {
            $this->app->forgetInstance(QueuedSender::class);
        }
        $transport = $this->app->bound(Transport::class) ? $this->app->make(Transport::class) : null;
        if ($transport instanceof SwitchableTransport) {
            $transport->route(null);
        }
        if ($this->state->connectionsReplaced) {
            config()->set('mobile-push.connections', $this->state->connectionsBackup ?? []);
        }
        if ($this->state->realEnvironment !== null) {
            $this->app->instance('env', $this->state->realEnvironment);
        }

        $gated = $this->state->gated;
        $this->state->intent = null;
        $this->state->wire = null;
        $this->state->realEnvironment = null;
        $this->state->queuedSwapped = false;
        $this->state->connectionsBackup = null;
        $this->state->connectionsReplaced = false;
        $this->state->seeded = [];
        $this->state->gated = $gated;
    }

    // ---------------------------------------------------------------- internals

    private function guard(bool $allow): void
    {
        $environment = $this->state->realEnvironment ?? (string) $this->app->environment();
        if ($environment === 'testing' || $allow || config('mobile-push.testing.allow_fakes') === true) {
            return;
        }

        throw new FakeNotAllowedException(sprintf(
            'MobilePush fakes are for tests: the application environment is "%s", not "testing". While a fake is active nothing reaches a push provider and the send pipeline treats the application as production, so it must never be switched on in a real deployment. If this is a deliberate test setup, pass true to fake()/fakeTransport() or set mobile-push.testing.allow_fakes.',
            $environment,
        ));
    }

    /** Live mode with every destination allowed: the application environment reads as production, mode never simulates. */
    private function goLive(): void
    {
        $this->state->realEnvironment ??= (string) $this->app->environment();
        $this->app->instance('env', 'production');

        $settings = $this->app->make(SendingSettings::class);
        if ($settings->mode === 'simulate') {
            config()->set('mobile-push.mode', 'live');
            $this->rebuild();
        }
    }

    /** The transport decorator. When the application bound its own Transport (a test double), it is wrapped and the pipeline rebuilt on top of it. */
    private function switchable(): SwitchableTransport
    {
        $current = $this->app->make(Transport::class);
        if ($current instanceof SwitchableTransport) {
            return $current;
        }
        $switch = new SwitchableTransport($current);
        $this->app->instance(Transport::class, $switch);
        $this->rebuild();

        return $switch;
    }

    private function rebuild(): void
    {
        foreach ([PushManager::class, SendingSettings::class, Senders::class, ConnectionFactory::class, Sender::class, Guards::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
        MobilePush::clearResolvedInstances();
    }

    private function seed(PushManager $manager, PushFake $fake): void
    {
        $scope = $manager->currentScope();
        $definitions = [];

        $configured = config('mobile-push.connections', []);
        foreach (is_array($configured) ? $configured : [] as $name => $config) {
            if (is_array($config)) {
                $definitions[] = [$scope, (string) $name, $config];
            }
        }
        if ($definitions === []) {
            $this->provideConnections(TestConnections::intent());
            foreach (TestConnections::intent() as $name => $config) {
                $definitions[] = [$scope, $name, $config];
            }
        }
        foreach ($definitions as [$definitionScope, $name, $config]) {
            $this->seedOne($manager, $fake, $definitionScope, $name, $config);
        }

        $fake->connectWith(function (string $name, string $driver, ?string $appId, ?string $scope) use ($manager, $fake): void {
            $config = $driver === 'fcm'
                ? ['driver' => 'fcm', 'project_id' => $appId ?? TestConnections::PROJECT, 'fake' => true]
                : ['driver' => 'apns', 'bundle_id' => $appId ?? TestConnections::BUNDLE, 'environment' => 'sandbox', 'fake' => true];
            if ($scope === null) {
                $scope = $manager->currentScope();
                $this->addConnection($name, $config); // configuration, so reset() takes it back
            } else {
                $manager->register($name, $config, $scope); // a runtime registration cannot be taken back
            }
            $this->seedOne($manager, $fake, $scope, $name, $config);
        });
    }

    /**
     * Test connections for an application that configures none; reset() puts the application's config back.
     *
     * @param  array<string, array<string, mixed>>  $connections
     */
    private function provideConnections(array $connections): void
    {
        $configured = config('mobile-push.connections', []);
        if (is_array($configured) && $configured !== []) {
            return;
        }
        $this->backUpConnections();
        config()->set('mobile-push.connections', $connections);
    }

    /** @param  array<string, mixed>  $config */
    private function addConnection(string $name, array $config): void
    {
        $this->backUpConnections();
        config()->set('mobile-push.connections.'.$name, $config);
    }

    private function backUpConnections(): void
    {
        if (! $this->state->connectionsReplaced) {
            $configured = config('mobile-push.connections', []);
            $this->state->connectionsBackup = is_array($configured) ? $configured : [];
            $this->state->connectionsReplaced = true;
        }
    }

    /** @param  array<mixed>  $config */
    private function seedOne(PushManager $manager, PushFake $fake, string $scope, string $name, array $config): void
    {
        try {
            $provider = ConnectionFactory::providerOf($config, $name);
        } catch (\Throwable) {
            return; // an invalid definition fails exactly as it does in production, when it is used
        }
        $binding = new BindingRef(
            $scope,
            $name,
            $provider,
            self::appId($provider, $config),
            $provider === Provider::Apns && ($config['environment'] ?? null) === 'sandbox' ? Environment::Sandbox : Environment::Production,
            '1',
        );
        $this->cache($manager)->put(
            hash('sha256', serialize($config)),
            new ResolvedConnection($binding, new FakeDriver($binding, $fake), new LiveSettings(true, [], true, true, true), [], []),
        );
        $this->state->seeded[$scope."\0".$name] = [$scope, $name];
    }

    private function unseed(): void
    {
        if ($this->state->seeded === [] || ! $this->app->resolved(PushManager::class)) {
            return;
        }
        $cache = $this->cache($this->app->make(PushManager::class));
        foreach ($this->state->seeded as [$scope, $name]) {
            $cache->forget($scope, $name);
        }
    }

    private function cache(PushManager $manager): ConnectionCache
    {
        $cache = (new \ReflectionProperty(PushManager::class, 'cache'))->getValue($manager);
        if (! $cache instanceof ConnectionCache) {
            throw new \LogicException('The push manager no longer keeps its connections in a ConnectionCache: MobilePush::fake() needs updating.');
        }

        return $cache;
    }

    /** @param  array<mixed>  $config */
    private static function appId(Provider $provider, array $config): string
    {
        $key = $provider === Provider::Apns ? 'bundle_id' : 'project_id';
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : ($provider === Provider::Apns ? TestConnections::BUNDLE : TestConnections::PROJECT);
    }
}
