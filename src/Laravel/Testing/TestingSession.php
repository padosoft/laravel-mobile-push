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

        $configured = config('mobile-push.connections', []);
        if (! is_array($configured) || $configured === []) {
            $this->state->connectionsBackup = is_array($configured) ? $configured : [];
            $this->state->connectionsReplaced = true;
            config()->set('mobile-push.connections', TestConnections::wire());
        }

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
            foreach (TestConnections::intent() as $name => $config) {
                $manager->register($name, $config, $scope);
                $definitions[] = [$scope, $name, $config];
            }
        }
        foreach ($fake->extraConnections() as $extra) {
            $driver = $extra['driver'];
            $config = $driver === 'fcm'
                ? ['driver' => 'fcm', 'project_id' => $extra['appId'] ?? TestConnections::PROJECT, 'fake' => true]
                : ['driver' => 'apns', 'bundle_id' => $extra['appId'] ?? TestConnections::BUNDLE, 'environment' => 'sandbox', 'fake' => true];
            $manager->register($extra['name'], $config, $extra['scope'] ?? $scope);
            $definitions[] = [$extra['scope'] ?? $scope, $extra['name'], $config];
        }

        $cache = $this->cache($manager);
        foreach ($definitions as [$definitionScope, $name, $config]) {
            try {
                $provider = ConnectionFactory::providerOf($config, $name);
            } catch (\Throwable) {
                continue; // an invalid definition fails exactly as it does in production, when it is used
            }
            $binding = new BindingRef(
                $definitionScope,
                $name,
                $provider,
                self::appId($provider, $config),
                $provider === Provider::Apns && ($config['environment'] ?? null) === 'sandbox' ? Environment::Sandbox : Environment::Production,
                '1',
            );
            $cache->put(
                hash('sha256', serialize($config)),
                new ResolvedConnection($binding, new FakeDriver($binding, $fake), new LiveSettings(true, [], true, true, true), [], []),
            );
            $this->state->seeded[$definitionScope."\0".$name] = [$definitionScope, $name];
        }
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
