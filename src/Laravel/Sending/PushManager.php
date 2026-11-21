<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Padosoft\MobilePush\Core\Driver\DestinationGate;
use Padosoft\MobilePush\Core\Driver\Driver;
use Padosoft\MobilePush\Core\Driver\GateContext;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Exceptions\PayloadTooLargeException;
use Padosoft\MobilePush\Core\Exceptions\UnknownConnectionException;
use Padosoft\MobilePush\Core\Exceptions\UnsupportedCapabilityException;
use Padosoft\MobilePush\Core\Exceptions\ValidationException;
use Padosoft\MobilePush\Core\Fcm\Topics\TopicSubscriptionClient;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Identity\Scope;
use Padosoft\MobilePush\Core\Message\CompileContext;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendReport;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Policy\Badge\BadgeResolver;
use Padosoft\MobilePush\Core\Policy\EndpointCatalog;
use Padosoft\MobilePush\Core\Policy\EndpointRole;
use Padosoft\MobilePush\Core\Policy\ExecutionMode;
use Padosoft\MobilePush\Core\Policy\Frequency\Subject;
use Padosoft\MobilePush\Core\Policy\LiveSettings;
use Padosoft\MobilePush\Core\Policy\ModeResolver;
use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Core\Policy\SafeModeDecision;
use Padosoft\MobilePush\Core\Policy\SafeModeGuard;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignBuilder;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignManager;
use Padosoft\MobilePush\Laravel\Contracts\PushDispatcher;
use Padosoft\MobilePush\Laravel\Contracts\QueuedSender;
use Padosoft\MobilePush\Laravel\Devices\DeviceEligibility;
use Padosoft\MobilePush\Laravel\Devices\DeviceOutcomeApplier;
use Padosoft\MobilePush\Laravel\Health\MetricsRecorder;
use Padosoft\MobilePush\Laravel\Health\OpsAlerter;
use Padosoft\MobilePush\Laravel\Logging\SinkManager;
use Padosoft\MobilePush\Laravel\Policies\PolicyGates;
use Padosoft\MobilePush\Laravel\Receipts\ReceiptMarkerIssuer;
use Padosoft\MobilePush\Laravel\Sending\Events\BindingMismatchDetected;
use Padosoft\MobilePush\Laravel\Sending\Events\PushBatchCompleted;
use Padosoft\MobilePush\Laravel\Sending\Gates\ConsentGate;
use Padosoft\MobilePush\Laravel\Sending\Gates\DeviceEligibilityGate;
use Padosoft\MobilePush\Laravel\Sending\Gates\DeviceKindsOnly;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The push manager (singleton, facade MobilePush): resolves connections per scope, caches their drivers in a
 * bounded LRU, and sends through the full pipeline with the framework observer attached.
 *
 * Connection resolution for (scope, name), first match wins: a runtime registration for that scope (register()),
 * the multi-tenant resolver (resolveConnectionUsing()), then `mobile-push.connections.<name>`. The scope always
 * comes from the code (->scope(), scopeUsing(), `mobile-push.scope`), never from client payload.
 */
final class PushManager implements PushDispatcher
{
    /** @var array<string, array<string, array<mixed>>> scope => name => config */
    private array $runtime = [];

    /** @var (\Closure(string, string): (array<mixed>|null))|null */
    private ?\Closure $resolver = null;

    /** @var (\Closure(): string)|null */
    private ?\Closure $scopeResolver = null;

    /** @var list<array{0: callable(mixed, mixed): mixed, 1: ConsentGate}> the callback as the application passed it, and its gate */
    private array $consentGates = [];

    /** @var array<string, array<string, ResolvedConnection>> scope => name => connection installed with useDriver() */
    private array $installed = [];

    private ?ExecutionMode $forcedMode = null;

    private ?LiveSettings $forcedLive = null;

    private bool $forcedEveryDestination = false;

    private readonly ConnectionCache $cache;

    public function __construct(
        private readonly Application $app,
        private readonly SendingSettings $settings,
        private readonly ConnectionFactory $factory,
        private readonly Senders $senders,
        private readonly TokenIndex $index,
    ) {
        $transport = $senders->transport();
        $this->cache = new ConnectionCache($settings->connectionCacheSize, static function (ResolvedConnection $connection) use ($transport): void {
            foreach ($connection->poolKeys as $poolKey) {
                $transport->close($poolKey);
            }
        });
    }

    // ---------------------------------------------------------------- fluent entry points

    /** A pending send on a named connection (null = `mobile-push.default`, or provider routing when that is unset). */
    public function connection(?string $name = null): PendingSend
    {
        return new PendingSend($this, null, $name ?? $this->settings->defaultConnection);
    }

    /** A pending send in an explicit scope (tenant). */
    public function scope(string $scope): PendingSend
    {
        return new PendingSend($this, Scope::normalize($scope), null);
    }

    /** A pending send routed by provider over `mobile-push.routes`. */
    public function to(mixed $target): PendingSend
    {
        return (new PendingSend($this, null, null))->to($target);
    }

    public function toToken(Platform $platform, #[\SensitiveParameter] string $token): PendingSend
    {
        return (new PendingSend($this, null, null))->toToken($platform, $token);
    }

    /** @param  iterable<string>  $tokens */
    public function toTokens(Platform $platform, #[\SensitiveParameter] iterable $tokens): PendingSend
    {
        return (new PendingSend($this, null, null))->toTokens($platform, $tokens);
    }

    public function toTopic(string $topic): PendingSend
    {
        return (new PendingSend($this, null, null))->toTopic($topic);
    }

    public function toCondition(string $condition): PendingSend
    {
        return (new PendingSend($this, null, null))->toCondition($condition);
    }

    /**
     * A campaign to a large audience (the campaigns module): ->via('ios')->audience(...)->draft() / ->dispatch().
     * The module's own checks apply when the campaign is created (durable profile and `mobile-push.campaigns.enabled`,
     * a ConfigurationException that says what to set otherwise).
     */
    public function campaign(Message $message): CampaignBuilder
    {
        if (! class_exists(CampaignManager::class) || ! $this->app->bound(CampaignManager::class)) {
            throw new ConfigurationException('Campaigns are not available: the campaigns module is not installed.');
        }

        return $this->app->make(CampaignManager::class)->campaign($message);
    }

    // ---------------------------------------------------------------- configuration at runtime

    /**
     * Add (or replace) a connection at runtime, for one scope (null = the current scope). Same keys as
     * `mobile-push.connections.<name>`. Validated at first use.
     *
     * @param  array<mixed>  $config
     */
    public function register(string $name, #[\SensitiveParameter] array $config, ?string $scope = null): void
    {
        $name = self::name($name);
        ConnectionFactory::providerOf($config, $name);
        $this->runtime[$this->scopeOrCurrent($scope)][$name] = $config;
    }

    /**
     * Undo register(): the runtime connection of that name in that scope (null = the current scope) is removed and
     * its cached driver dropped (its transport pools closed). A configured connection of the same name, or one
     * supplied by the resolver, is used again from the next send. A no-op when nothing was registered.
     */
    public function unregister(string $name, ?string $scope = null): void
    {
        $name = self::name($name);
        $scope = $this->scopeOrCurrent($scope);
        if (! isset($this->runtime[$scope][$name])) {
            return;
        }
        unset($this->runtime[$scope][$name]);
        if ($this->runtime[$scope] === []) {
            unset($this->runtime[$scope]);
        }
        $this->cache->forget($scope, $name);
    }

    /**
     * Extension and testing seam: send through this driver for the connection `$name` in `$scope` (null = the
     * current scope), instead of building one from the configuration. The installed driver wins over a runtime
     * registration, the resolver and `mobile-push.connections`, and the name is listed by connectionNames() while it
     * is installed. Nothing is cached and no transport pool is owned on its behalf. The driver's binding must carry
     * the same scope and name.
     *
     * `$live` is the connection's live settings (default: none, so outside production the safe-mode guard blocks
     * every live send through it unless the execution is forced). forgetDriver() removes it.
     */
    public function useDriver(string $name, Driver $driver, ?string $scope = null, ?LiveSettings $live = null): void
    {
        $name = self::name($name);
        $scope = $this->scopeOrCurrent($scope);
        $binding = $driver->binding();
        if ($binding->scope !== $scope || $binding->name !== $name) {
            throw new ConfigurationException(sprintf(
                'The driver installed for the connection "%s" in scope "%s" must have a binding with the same scope and name (it has "%s" in scope "%s").',
                $name, $scope, $binding->name, $binding->scope,
            ));
        }
        $this->installed[$scope][$name] = new ResolvedConnection($binding, $driver, $live ?? new LiveSettings, [], []);
    }

    /** Remove a driver installed with useDriver(); the connection resolves from its configuration again. A no-op when none is installed. */
    public function forgetDriver(string $name, ?string $scope = null): void
    {
        $name = self::name($name);
        $scope = $this->scopeOrCurrent($scope);
        unset($this->installed[$scope][$name]);
        if (($this->installed[$scope] ?? null) === []) {
            unset($this->installed[$scope]);
        }
    }

    /** Whether a driver is installed with useDriver() for this connection. */
    public function hasInstalledDriver(string $name, ?string $scope = null): bool
    {
        return isset($this->installed[$this->scopeOrCurrent($scope)][self::name($name)]);
    }

    /**
     * Supply connection configs on demand (multi-tenant apps; also used inside queue workers to resolve a job's
     * binding). Return null when the scope has no connection of that name.
     *
     * @param  callable(string $name, string $scope): (array<mixed>|null)  $resolver
     */
    public function resolveConnectionUsing(callable $resolver): void
    {
        $this->resolver = \Closure::fromCallable($resolver);
    }

    /**
     * Supply the current tenant scope (e.g. from the authenticated tenant). Never derive it from client payload.
     *
     * `null` restores the default (`mobile-push.scope`), for hosts that set the resolver per request (Octane).
     *
     * @param  (callable(): string)|null  $resolver
     */
    public function scopeUsing(?callable $resolver): void
    {
        $this->scopeResolver = $resolver === null ? null : \Closure::fromCallable($resolver);
    }

    /** Undo scopeUsing(): the current scope is `mobile-push.scope` again. Same as scopeUsing(null). */
    public function forgetScopeResolver(): void
    {
        $this->scopeResolver = null;
    }

    /**
     * Undo resolveConnectionUsing(): connections come from register() and `mobile-push.connections` only. Cached
     * drivers resolved through the old resolver are kept until forgetConnection() / flush().
     */
    public function forgetConnectionResolver(): void
    {
        $this->resolver = null;
    }

    /**
     * Register the application's consent / eligibility check, evaluated immediately before the network for every
     * destination of every send. Per destination (default): fn (Destination $d, GateContext $c): bool|GateDenial.
     * With `$batch`: fn (GateContext $c, list<SendItem> $items): array<int, bool|GateDenial|null> keyed by position.
     * `false` = Skipped with PolicyIneligible. Never bypassed by PushMessage::bypassFrequencyCap().
     *
     * @param  callable(mixed, mixed): mixed  $check
     */
    public function eligibleUsing(callable $check, bool $batch = false): void
    {
        $this->consentGates[] = [$check, new ConsentGate($check, $batch)];
    }

    /**
     * Undo eligibleUsing(): remove every check registered with this very callable (the same closure instance, or an
     * identical string / array callable). Returns how many were removed.
     *
     * @param  callable(mixed, mixed): mixed  $check
     */
    public function forgetEligibility(callable $check): int
    {
        $before = count($this->consentGates);
        $this->consentGates = array_values(array_filter($this->consentGates, static fn (array $entry): bool => $entry[0] !== $check));

        return $before - count($this->consentGates);
    }

    /** Number of eligibility checks registered with eligibleUsing(). */
    public function eligibilityChecks(): int
    {
        return count($this->consentGates);
    }

    public function currentScope(): string
    {
        $scope = $this->scopeResolver === null ? $this->settings->scope : ($this->scopeResolver)();

        return Scope::normalize($scope);
    }

    /**
     * Names of the connections known in a scope (configured + registered at runtime). Connections supplied only by
     * the resolver cannot be listed.
     *
     * @return list<string>
     */
    public function connectionNames(?string $scope = null): array
    {
        $scope = $this->scopeOrCurrent($scope);
        $names = array_map('strval', array_keys($this->configuredConnections()));
        foreach ([...array_keys($this->runtime[$scope] ?? []), ...array_keys($this->installed[$scope] ?? [])] as $name) {
            if (! in_array((string) $name, $names, true)) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /** Drop the cached driver of a connection and close its transport pools (the configuration is kept). */
    public function forgetConnection(string $name, ?string $scope = null): void
    {
        $this->cache->forget($this->scopeOrCurrent($scope), $name);
    }

    /** Drop every cached driver and close every transport pool they used. */
    public function flush(): void
    {
        $this->cache->flush();
    }

    /** Number of resolved connections currently cached (bounded by `mobile-push.connection_cache.size`). */
    public function cachedConnections(): int
    {
        return $this->cache->count();
    }

    public function settings(): SendingSettings
    {
        return $this->settings;
    }

    /**
     * @internal Testing seam (used by the testing module): force the execution mode (and optionally the live
     * settings) of every send's default options, whatever the configuration and the application environment say.
     * Every place that decides the mode reads those options (PendingSend, the notification channel, the queue jobs
     * and the relay through PushDispatcher::defaultOptions(), Live Activities channel management, the console).
     *
     * With `$everyDestination` the safe-mode guard authorises every destination and operation as it does in
     * production (no `live.only_to` allowlist, no `live.allow_*` flag needed): the options carry
     * `applicationEnvironment = "production"` for the guard only. The application environment itself
     * (`app()->environment()`, `app()->isProduction()`) is never changed.
     *
     * null clears the whole override. Never call it from application code: forcing `live` bypasses the safe-mode default.
     */
    public function forceExecution(?ExecutionMode $mode, ?LiveSettings $live = null, bool $everyDestination = false): void
    {
        $this->forcedMode = $mode;
        $this->forcedLive = $mode === null ? null : $live;
        $this->forcedEveryDestination = $mode !== null && $everyDestination;
    }

    /** @internal Whether forceExecution() is in effect. */
    public function executionForced(): bool
    {
        return $this->forcedMode !== null;
    }

    // ---------------------------------------------------------------- server-side badge (policies module)

    /**
     * Reset the server-side badge counter of an owner to 0, typically when the app opens. With
     * `mobile-push.badge.per_binding` the counter is per connection: name it (null resets the cross-connection
     * counter only). A no-op without the policies module.
     */
    public function resetBadge(object $owner, ?string $connection = null, ?string $scope = null): void
    {
        $resolver = $this->badgeResolver();
        if ($resolver === null) {
            return;
        }
        $scope = $this->scopeOrCurrent($scope);
        $resolver->counter()->reset($scope, $resolver->subjectKey(self::ownerSubject($owner), $connection === null ? null : $this->binding($connection, $scope)));
    }

    /** The current server-side badge number of an owner (0 when never incremented or without the policies module). */
    public function currentBadge(object $owner, ?string $connection = null, ?string $scope = null): int
    {
        $resolver = $this->badgeResolver();
        if ($resolver === null) {
            return 0;
        }
        $scope = $this->scopeOrCurrent($scope);

        return $resolver->counter()->current($scope, $resolver->subjectKey(self::ownerSubject($owner), $connection === null ? null : $this->binding($connection, $scope)));
    }

    /** @internal The badge resolver of the policies module, or null when it is not installed. */
    public function badgeResolver(): ?BadgeResolver
    {
        if (! class_exists(BadgeResolver::class) || ! $this->app->bound(BadgeResolver::class)) {
            return null;
        }

        return $this->app->make(BadgeResolver::class);
    }

    /**
     * @internal Whether at least one of these destinations of one owner can pass the SIDE-EFFECT-FREE gates, so the
     * badge counter is incremented only for an owner somebody will actually receive the push for. Evaluated: the device
     * registry eligibility and the application's eligibility checks (eligibleUsing()), in the order a send runs them.
     * NOT evaluated, because they consume budget or depend on the moment of the send: the frequency cap, safe mode and
     * the guards, and per-send gates added with withGate(). A broken gate counts as "may pass" (the send itself then
     * decides and reports it). A deferral is not a denial: the push is still going to be sent.
     *
     * @param  iterable<Destination|SendItem>  $destinations
     */
    public function anyDestinationEligible(BindingRef $binding, Message $message, iterable $destinations, TrafficClass $trafficClass, ?string $effectKey): bool
    {
        $gates = [];
        if ($this->devicesEnabled()) {
            $gates[] = new DeviceEligibilityGate($this->app->make(DeviceEligibility::class));
        }
        foreach ($this->consentGates as [, $gate]) {
            $gates[] = $gate;
        }

        $remaining = [];
        foreach ($destinations as $destination) {
            $remaining[] = SendItem::of($destination);
        }
        if ($gates === [] || $remaining === []) {
            return $remaining !== [];
        }

        $context = new GateContext($binding, $message, $trafficClass, $this->now(), $effectKey);
        try {
            foreach ($gates as $gate) {
                $denied = $gate->check($context, $remaining);
                $kept = [];
                foreach ($remaining as $position => $item) {
                    if (! isset($denied[$position]) || $denied[$position]->status !== SendStatus::Skipped) {
                        $kept[] = $item;
                    }
                }
                $remaining = $kept;
                if ($remaining === []) {
                    return false;
                }
            }
        } catch (\Throwable) {
            return true;
        }

        return true;
    }

    /** The policy subject of an owner model (morph class + key, as the device registry stores them). */
    private static function ownerSubject(object $owner): string
    {
        if ($owner instanceof Model) {
            $key = $owner->getKey();
            if (is_int($key) || is_string($key)) {
                return Subject::owner($owner->getMorphClass(), (string) $key);
            }
        }
        throw new ConfigurationException(sprintf('A badge belongs to an owner model (Eloquent), %s given.', get_debug_type($owner)));
    }

    // ---------------------------------------------------------------- PushDispatcher

    public function binding(string $connection, ?string $scope = null): BindingRef
    {
        return $this->resolve($this->scopeOrCurrent($scope), $connection)->binding;
    }

    public function send(BindingRef $binding, Message $message, iterable $destinations, ?SendOptions $options = null): SendReport
    {
        $current = $this->current($binding);
        if (! $current instanceof ResolvedConnection) {
            return $this->mismatch($binding->scope, $binding->name, $binding, $current, $destinations, $options === null ? TrafficClass::Transactional : $options->trafficClass);
        }

        $options ??= $this->optionsFor($current);
        $markers = null;
        if ($options->receiptMarker !== null) {
            // A marker only for destinations with a ledger delivery id, remembered so the log sinks can redact it.
            $markers = new ReceiptMarkers;
            $destinations = $markers->watch($destinations);
            $options = $options->withReceiptMarker($markers->wrap($options->receiptMarker));
        }
        $observer = $this->observer($current, $options, $markers);
        $started = hrtime(true);

        $sender = $this->senders->for($current->binding->provider)->withObserver($observer);
        $operations = $this->operations($current->binding, $options->trafficClass);
        if ($operations !== null) {
            $sender = $sender->withObserver($operations);
        }
        $report = $sender->send($current->driver, $message, $destinations, $options);

        return new SendReport($this->completing($report, $current->binding, $options->trafficClass, $started));
    }

    public function driver(BindingRef $binding): Driver
    {
        $current = $this->current($binding);
        if (! $current instanceof ResolvedConnection) {
            throw new UnknownConnectionException(self::mismatchHint($binding->scope, $binding->name, $current));
        }

        return $current->driver;
    }

    /**
     * The FCM topic subscription client of a connection (subscribe / unsubscribe installations, list their
     * subscriptions), built with the connection's OAuth credential, the transport, the endpoint catalog and the
     * SAME safe-mode gate a send goes through: in simulate mode, or when live sending is not allowed for that
     * connection outside production (`live.allow_topics`, `live.only_to`), no request is made and the result says it
     * was simulated or blocked. `$connection` null = the only FCM connection of the scope.
     *
     * @throws ConfigurationException when the connection is not an FCM connection, or none / several FCM connections exist
     * @throws UnknownConnectionException when the connection does not exist
     */
    public function topicSubscriptions(?string $connection = null, ?string $scope = null): TopicSubscriptionClient
    {
        $scope = $this->scopeOrCurrent($scope);
        $name = $connection === null ? $this->routeFor($scope, Provider::Fcm) : self::name($connection);
        if ($this->providerOf($scope, $name) !== Provider::Fcm) {
            throw new ConfigurationException(sprintf('The connection "%s" is not an FCM connection: topic subscriptions exist only on FCM.', $name));
        }
        $resolved = $this->resolve($scope, $name);
        $binding = $resolved->binding;
        $driver = $resolved->driver;
        $provider = $driver->tokenProvider(0)
            ?? throw new ConfigurationException(sprintf('The connection "%s" has no OAuth credential for the topic API.', $name));
        $options = $this->optionsFor($resolved);
        $guard = $this->app->make(SafeModeGuard::class);
        $endpoints = $this->app->bound(EndpointCatalog::class) ? $this->app->make(EndpointCatalog::class) : new EndpointCatalog($options->applicationEnvironment ?? '');

        return new TopicSubscriptionClient(
            $this->senders->transport(),
            $driver->connectionProfile(EndpointRole::FcmApi, 0),
            $endpoints,
            $binding->appId,
            $provider,
            static fn (OperationKind $operation, Destination $destination): SafeModeDecision => $guard->decide(
                $options->effectiveMode, $options->applicationEnvironment ?? '', $binding, $destination, $operation, $options->live,
            ),
        );
    }

    public function defaultOptions(BindingRef $binding): SendOptions
    {
        $current = $this->current($binding);
        if (! $current instanceof ResolvedConnection) {
            throw new UnknownConnectionException(self::mismatchHint($binding->scope, $binding->name, $current));
        }

        return $this->optionsFor($current);
    }

    // ---------------------------------------------------------------- used by PendingSend

    /** @internal */
    public function resolve(string $scope, string $name): ResolvedConnection
    {
        $scope = Scope::normalize($scope);
        $name = self::name($name);
        if (isset($this->installed[$scope][$name])) {
            return $this->installed[$scope][$name];
        }
        $config = $this->configFor($scope, $name)
            ?? throw new UnknownConnectionException(sprintf('No push connection named "%s" in scope "%s". Define it in mobile-push.connections, register it with MobilePush::register(), or supply it with MobilePush::resolveConnectionUsing().', $name, $scope));
        $hash = hash('sha256', serialize($config));

        $cached = $this->cache->find($scope, $name, $hash);
        if ($cached !== null) {
            return $cached;
        }

        $connection = $this->factory->make($scope, $name, $config);
        $this->cache->put($hash, $connection);

        return $connection;
    }

    /** @internal The provider of a connection, read from its config without resolving secrets. */
    public function providerOf(string $scope, string $name): Provider
    {
        $installed = $this->installed[Scope::normalize($scope)][self::name($name)] ?? null;
        if ($installed !== null) {
            return $installed->binding->provider;
        }
        $config = $this->configFor(Scope::normalize($scope), self::name($name))
            ?? throw new UnknownConnectionException(sprintf('No push connection named "%s" in scope "%s".', $name, $scope));

        return ConnectionFactory::providerOf($config, $name);
    }

    /**
     * @internal The only route of a provider in a scope; with none or several, a ConfigurationException that says
     * how to name the connection.
     */
    public function routeFor(string $scope, Provider $provider): string
    {
        $candidates = [];
        foreach ($this->routeNames($scope) as $name) {
            if ($this->providerOf($scope, $name) === $provider) {
                $candidates[] = $name;
            }
        }

        if ($candidates === []) {
            throw new ConfigurationException(sprintf(
                'No %s push connection is available in scope "%s". Configure one in mobile-push.connections (driver "%s"), list it in mobile-push.routes, or name it with MobilePush::connection(\'...\').',
                $provider->value, $scope, $provider->value,
            ));
        }
        if (count($candidates) > 1) {
            throw new ConfigurationException(sprintf(
                'Several %s push connections are available in scope "%s" (%s): name the one to use with MobilePush::connection(\'...\'). The package never guesses the app from a token.',
                $provider->value, $scope, implode(', ', $candidates),
            ));
        }

        return $candidates[0];
    }

    /**
     * @internal Connections `to($notifiable)` sends through.
     *
     * @return list<string>
     */
    public function routeNames(string $scope): array
    {
        return $this->settings->routes ?? $this->connectionNames($scope);
    }

    /** @internal */
    public function devicesEnabled(): bool
    {
        return (bool) $this->config('mobile-push.devices.enabled', false);
    }

    /** @internal */
    public function queuedSender(): QueuedSender
    {
        if (! $this->app->bound(QueuedSender::class)) {
            throw new ConfigurationException('Queued sending is not available: no Padosoft\MobilePush\Laravel\Contracts\QueuedSender is bound (the queue module is not installed or not enabled). Use ->send() to send now.');
        }

        return $this->app->make(QueuedSender::class);
    }

    /**
     * @internal Pre-flight of one connection of a send, before anything of the send reaches the network or the queue:
     * the message is compiled against one sample destination with the connection's own compiler (no receipt marker,
     * no message id, nothing kept, no request). Returns the message-level validation error the send would raise for
     * this connection, or null. Destination-level problems (an invalid destination, an oversized payload for one
     * destination) are left to the send, which reports them per destination.
     */
    public function preflight(BindingRef $binding, Message $message, Destination $sample, bool $validateOnly = false): ?ValidationException
    {
        $current = $this->current($binding);
        if (! $current instanceof ResolvedConnection) {
            return null; // reported as a binding mismatch by the send itself
        }
        $driver = $current->driver;
        try {
            if (! $driver->compiler()->supports($message)) {
                throw new ValidationException(sprintf('The %s driver cannot send a message of type %s.', $current->binding->provider->value, $message::class));
            }
            if ($validateOnly && ! $driver->supportsValidateOnly()) {
                throw new UnsupportedCapabilityException(sprintf('The %s provider has no validate-only (dry run) mode.', $current->binding->provider->value));
            }
            $driver->compiler()->compile($message, $sample, new CompileContext($current->binding, $this->now(), validateOnly: $validateOnly));
        } catch (InvalidDestinationException|PayloadTooLargeException) {
            return null;
        } catch (ValidationException $e) {
            return $e;
        }

        return null;
    }

    /**
     * @internal Rejected results for the destinations of a connection whose message failed the pre-flight (see
     * preflight()): reason PayloadInvalid with the validation message as hint, recorded in the send log and the
     * events like any result. Nothing is sent and nothing is queued for them.
     *
     * @param  iterable<Destination|SendItem>  $destinations
     */
    public function refused(BindingRef $binding, ValidationException $error, iterable $destinations, TrafficClass $trafficClass): SendReport
    {
        $observer = new SendingObserver(
            $binding, $trafficClass, false, $this->settings->perResultEvents, $this->events(), $this->index,
            null, $this->sinks(), static fn (): array => [], $this->logger(),
        );
        $operations = $this->operations($binding, $trafficClass);
        $reason = Reason::of(ReasonCode::PayloadInvalid, null, sprintf('Not sent on the %s connection "%s": %s', $binding->provider->value, $binding->name, $error->getMessage()));

        $results = (static function () use ($destinations, $reason, $observer, $operations): \Generator {
            foreach ($destinations as $value) {
                $item = SendItem::of($value);
                $result = new SendResult($item->destination, SendStatus::Rejected, $reason, deliveryId: $item->deliveryId);
                $observer->record($result, $item);
                $operations?->record($result);
                yield $result;
            }
        })();

        return new SendReport($results);
    }

    private function now(): \DateTimeImmutable
    {
        return $this->app->bound(ClockInterface::class) ? $this->app->make(ClockInterface::class)->now() : new \DateTimeImmutable;
    }

    /**
     * @internal Skipped(BindingMismatch) results for destinations whose binding no longer matches, plus the event.
     *
     * @param  iterable<Destination|SendItem>  $destinations
     */
    public function mismatch(string $scope, string $name, ?BindingRef $expected, ?BindingRef $current, iterable $destinations, TrafficClass $trafficClass): SendReport
    {
        $hint = self::mismatchHint($scope, $name, $current);
        $this->dispatchSafely(new BindingMismatchDetected($scope, $name, $expected, $current, $hint));

        $recordAs = $expected ?? $current;
        $observer = $recordAs === null ? null : new SendingObserver(
            $recordAs, $trafficClass, false, $this->settings->perResultEvents, $this->events(), $this->index,
            null, $this->sinks(), static fn (): array => [], $this->logger(),
        );
        $operations = $recordAs === null ? null : $this->operations($recordAs, $trafficClass);
        $reason = Reason::of(ReasonCode::BindingMismatch, null, $hint);

        $results = (static function () use ($destinations, $reason, $observer, $operations): \Generator {
            foreach ($destinations as $value) {
                $item = SendItem::of($value);
                $result = new SendResult($item->destination, SendStatus::Skipped, $reason, deliveryId: $item->deliveryId);
                $observer?->record($result, $item);
                $operations?->record($result);
                yield $result;
            }
        })();

        return new SendReport($results);
    }

    // ---------------------------------------------------------------- internals

    /**
     * The current connection for a captured binding: same identity (scope, provider, app, environment) behind the
     * same name. A different generation of that identity is fine (the current credential is used).
     *
     * @return ResolvedConnection|BindingRef|null the connection; the binding the name points to now (mismatch); null (unresolvable)
     */
    private function current(BindingRef $binding): ResolvedConnection|BindingRef|null
    {
        try {
            $current = $this->resolve($binding->scope, $binding->name);
        } catch (UnknownConnectionException) {
            return null;
        }

        return $current->binding->sameIdentityAs($binding) ? $current : $current->binding;
    }

    /**
     * The default options of a connection: execution mode, live settings, retry policy, the gates (registry
     * eligibility, then the application's consent, then the enabled sending policies) and, when the receipts module
     * is active (receipts enabled + durable profile), the receipt-marker callback. The callback is used only for
     * destinations that carry a ledger delivery id (see send()), so the synchronous path never issues a marker.
     */
    private function optionsFor(ResolvedConnection $connection): SendOptions
    {
        return new SendOptions(
            trafficClass: TrafficClass::Transactional,
            // Forced for every destination: what the safe-mode guard authorises against, never the app environment.
            applicationEnvironment: $this->forcedEveryDestination ? ModeResolver::PRODUCTION : $this->app->environment(),
            configuredMode: $this->settings->mode,
            mode: $this->forcedMode,
            live: $this->forcedLive ?? $connection->live,
            retryPolicy: $this->settings->retry,
            receiptMarker: $this->receiptMarker($connection->binding),
            gates: $this->gates(),
        );
    }

    /**
     * Order matters: a destination that is not eligible (or did not consent) must never consume a unit of a cap.
     * Registry eligibility and the policy gates only see device-registry kinds (DeviceKindsOnly): Live Activity
     * transitions, topics and broadcasts are neither looked up in the devices table nor frequency-capped. The
     * application's consent check sees every destination (it receives the typed destination and decides).
     *
     * @return list<DestinationGate>
     */
    private function gates(): array
    {
        $gates = [];
        if ($this->devicesEnabled()) {
            $gates[] = new DeviceEligibilityGate($this->app->make(DeviceEligibility::class));
        }
        foreach ($this->consentGates as [, $gate]) {
            $gates[] = $gate;
        }
        foreach ($this->policyGates() as $gate) {
            $gates[] = $gate instanceof DeviceKindsOnly ? $gate : new DeviceKindsOnly($gate);
        }

        return $gates;
    }

    /** @return list<DestinationGate> the enabled sending-policy gates of the policies module (frequency cap) */
    private function policyGates(): array
    {
        if (! class_exists(PolicyGates::class) || ! $this->app->bound(PolicyGates::class)) {
            return [];
        }

        return $this->app->make(PolicyGates::class)->gates();
    }

    /** @return (\Closure(Destination, string): ?string)|null */
    private function receiptMarker(BindingRef $binding): ?\Closure
    {
        if (! class_exists(ReceiptMarkerIssuer::class) || ! $this->app->bound(ReceiptMarkerIssuer::class)) {
            return null;
        }
        if ($this->config('mobile-push.receipts.enabled', false) !== true || $this->settings->deliveryProfile !== 'durable') {
            return null;
        }

        return $this->app->make(ReceiptMarkerIssuer::class)->callback($binding->scope);
    }

    /** Metrics + operational alerts for one call; null when the health module is not installed. */
    private function operations(BindingRef $binding, TrafficClass $trafficClass): ?OperationsObserver
    {
        $metrics = class_exists(MetricsRecorder::class) && $this->app->bound(MetricsRecorder::class) ? $this->app->make(MetricsRecorder::class) : null;
        $alerter = class_exists(OpsAlerter::class) && $this->app->bound(OpsAlerter::class) ? $this->app->make(OpsAlerter::class) : null;
        if ($metrics === null && $alerter === null) {
            return null;
        }

        return new OperationsObserver($binding, $trafficClass, $metrics, $alerter, $this->logger());
    }

    private function observer(ResolvedConnection $connection, SendOptions $options, ?ReceiptMarkers $markers = null): SendingObserver
    {
        $devices = null;
        if ($this->devicesEnabled() && $this->app->bound(DeviceOutcomeApplier::class)) {
            $devices = $this->app->make(DeviceOutcomeApplier::class);
        }

        return new SendingObserver(
            $connection->binding,
            $options->trafficClass,
            $options->validateOnly,
            $this->settings->perResultEvents,
            $this->events(),
            $this->index,
            $devices instanceof DeviceOutcomeApplier ? $devices : null,
            $this->sinks(),
            static fn (): array => $connection->bearers(),
            $this->logger(),
            $markers,
        );
    }

    /** @return \Generator<int, SendResult> */
    private function completing(SendReport $report, BindingRef $binding, TrafficClass $trafficClass, int $started): \Generator
    {
        $counts = [];
        foreach (SendStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }
        $total = 0;
        foreach ($report->results() as $result) {
            $counts[$result->status->value]++;
            $total++;
            yield $result;
        }
        $this->dispatchSafely(new PushBatchCompleted($binding, $counts, $total, $trafficClass, (hrtime(true) - $started) / 1e6));
    }

    /** @return array<mixed>|null */
    private function configFor(string $scope, string $name): ?array
    {
        if (isset($this->runtime[$scope][$name])) {
            return $this->runtime[$scope][$name];
        }
        if ($this->resolver !== null) {
            $config = ($this->resolver)($name, $scope);
            if ($config !== null) {
                return $config;
            }
        }
        $configured = $this->configuredConnections()[$name] ?? null;
        if ($configured === null) {
            return null;
        }
        if (! is_array($configured)) {
            throw new ConfigurationException("mobile-push.connections.{$name} must be an array.");
        }

        return $configured;
    }

    /** @return array<array-key, mixed> */
    private function configuredConnections(): array
    {
        $connections = $this->config('mobile-push.connections', []);
        if (! is_array($connections)) {
            throw new ConfigurationException('mobile-push.connections must be an array of connection name => config.');
        }

        return $connections;
    }

    private function config(string $key, mixed $default): mixed
    {
        return $this->app->make('config')->get($key, $default);
    }

    private function scopeOrCurrent(?string $scope): string
    {
        return $scope === null ? $this->currentScope() : Scope::normalize($scope);
    }

    private function events(): ?Dispatcher
    {
        if (! $this->app->bound('events')) {
            return null;
        }

        return $this->app->make('events');
    }

    private function sinks(): ?SinkManager
    {
        if (! $this->app->bound(SinkManager::class)) {
            return null;
        }

        return $this->app->make(SinkManager::class);
    }

    private function logger(): ?LoggerInterface
    {
        try {
            return $this->app->make(LoggerInterface::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function dispatchSafely(object $event): void
    {
        try {
            $this->events()?->dispatch($event);
        } catch (\Throwable $e) {
            try {
                $this->logger()?->warning('mobile-push: an event listener failed; the send is unaffected.', ['event' => $event::class, 'exception' => $e::class]);
            } catch (\Throwable) {
                // ignored
            }
        }
    }

    private static function mismatchHint(string $scope, string $name, ?BindingRef $current): string
    {
        return $current === null
            ? sprintf('The connection "%s" no longer resolves in scope "%s"; nothing was sent and no other connection was used. Re-create the push for a configured connection.', $name, $scope)
            : sprintf('The connection "%s" in scope "%s" now points to a different app, provider or environment than when this push was prepared; nothing was sent and no other connection was used.', $name, $scope);
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new ConfigurationException('A push connection name cannot be empty.');
        }

        return $name;
    }
}
