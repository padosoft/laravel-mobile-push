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
use Padosoft\MobilePush\Core\Identity\Scope;
use Padosoft\MobilePush\Core\Policy\ExecutionMode;
use Padosoft\MobilePush\Core\Policy\LiveSettings;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Laravel\Contracts\QueuedSender;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Sending\ConnectionFactory;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Padosoft\MobilePush\Laravel\Sending\Senders;
use Padosoft\MobilePush\Laravel\Sending\SendingSettings;

/**
 * Turns the fakes on and off for one application. Nothing is swapped until fake() or fakeTransport() is called, and
 * reset() puts everything back (the transport, the queue binding, the connections, the manager's forced execution,
 * the fake drivers and the recording gate), so nothing is left between two tests.
 *
 * While a fake is active the manager's execution is forced live for every destination
 * (PushManager::forceExecution(Live, everyDestination: true)): the `auto` mode does not simulate and the safe-mode
 * guard does not ask for a test-device allowlist. The application environment is never touched:
 * `app()->environment()` and `app()->isProduction()` keep returning the real values inside a faked test.
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

        $fake = new PushFake;
        $this->switchable()->route(new AcceptingTransport);
        $manager = $this->goLive();

        $gate = function (mixed $context, mixed $items): array {
            /** @var GateContext $context */
            /** @var list<SendItem> $items */
            return $this->state->intent?->capture($context, $items) ?? [];
        };
        $manager->eligibleUsing($gate, true);
        $this->state->gate = $gate;
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

        $this->provideConnections(TestConnections::wire());

        $builder = new FakeTransportBuilder;
        $this->switchable()->route($builder->transport());
        $this->goLive();
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
        if (! $this->state->dirty()) {
            return;
        }

        $this->state->intent?->deactivate();
        $manager = $this->state->manager;
        if ($manager !== null) {
            foreach ($this->state->installed as [$scope, $name]) {
                $manager->forgetDriver($name, $scope);
            }
            if ($this->state->gate !== null) {
                $manager->forgetEligibility($this->state->gate);
            }
            $manager->forceExecution(null);
        }
        if ($this->state->queuedSwapped) {
            $this->app->forgetInstance(QueuedSender::class);
        }
        $transport = $this->app->bound(Transport::class) ? $this->app->make(Transport::class) : null;
        if ($transport instanceof SwitchableTransport) {
            $transport->route(null);
        }
        if ($this->state->connectionsReplaced) {
            config()->set('mobile-push.connections', $this->state->connectionsBackup ?? []);
            if ($manager !== null) {
                foreach ($this->state->provided as $name) {
                    $manager->forgetConnection($name); // the drivers built from the test credentials
                }
            }
        }

        $this->state->intent = null;
        $this->state->wire = null;
        $this->state->manager = null;
        $this->state->gate = null;
        $this->state->queuedSwapped = false;
        $this->state->connectionsBackup = null;
        $this->state->connectionsReplaced = false;
        $this->state->provided = [];
        $this->state->installed = [];
    }

    // ---------------------------------------------------------------- internals

    private function guard(bool $allow): void
    {
        $environment = (string) $this->app->environment();
        if ($environment === 'testing' || $allow || config('mobile-push.testing.allow_fakes') === true) {
            return;
        }

        throw new FakeNotAllowedException(sprintf(
            'MobilePush fakes are for tests: the application environment is "%s", not "testing". While a fake is active nothing reaches a push provider and every send runs live with every destination allowed, so it must never be switched on in a real deployment. If this is a deliberate test setup, pass true to fake()/fakeTransport() or set mobile-push.testing.allow_fakes.',
            $environment,
        ));
    }

    /** Live mode with every destination allowed, through the manager's execution override (the environment is untouched). */
    private function goLive(): PushManager
    {
        $manager = $this->app->make(PushManager::class);
        $manager->forceExecution(ExecutionMode::Live, null, everyDestination: true);
        $this->state->manager = $manager;

        return $manager;
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
                $definitions[(string) $name] = $config;
            }
        }
        if ($definitions === []) {
            $definitions = TestConnections::intent(); // known to the manager through the installed drivers only
        }
        foreach ($definitions as $name => $config) {
            $this->install($manager, $fake, $scope, (string) $name, $config);
        }

        $fake->connectWith(function (string $name, string $driver, ?string $appId, ?string $scope) use ($manager, $fake): void {
            $config = $driver === 'fcm'
                ? ['driver' => 'fcm', 'project_id' => $appId ?? TestConnections::PROJECT, 'fake' => true]
                : ['driver' => 'apns', 'bundle_id' => $appId ?? TestConnections::BUNDLE, 'environment' => 'sandbox', 'fake' => true];
            $this->install($manager, $fake, $scope ?? $manager->currentScope(), $name, $config);
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
        $this->state->connectionsBackup = is_array($configured) ? $configured : [];
        $this->state->connectionsReplaced = true;
        $this->state->provided = array_keys($connections);
        config()->set('mobile-push.connections', $connections);
    }

    /** @param  array<mixed>  $config */
    private function install(PushManager $manager, PushFake $fake, string $scope, string $name, array $config): void
    {
        try {
            $provider = ConnectionFactory::providerOf($config, $name);
        } catch (\Throwable) {
            return; // an invalid definition fails exactly as it does in production, when it is used
        }
        $scope = Scope::normalize($scope);
        $binding = new BindingRef(
            $scope,
            $name,
            $provider,
            self::appId($provider, $config),
            $provider === Provider::Apns && ($config['environment'] ?? null) === 'sandbox' ? Environment::Sandbox : Environment::Production,
            '1',
        );
        $manager->useDriver($name, new FakeDriver($binding, $fake), $scope, new LiveSettings(true, [], true, true, true));
        $this->state->installed[$scope."\0".$name] = [$scope, $name];
    }

    /** @param  array<mixed>  $config */
    private static function appId(Provider $provider, array $config): string
    {
        $key = $provider === Provider::Apns ? 'bundle_id' : 'project_id';
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : ($provider === Provider::Apns ? TestConnections::BUNDLE : TestConnections::PROJECT);
    }
}
