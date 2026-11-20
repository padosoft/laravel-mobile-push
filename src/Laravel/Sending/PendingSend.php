<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;
use Padosoft\MobilePush\Core\Driver\DestinationGate;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Exceptions\UnknownConnectionException;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmCondition;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Identity\Scope;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Outcome\SendReport;
use Padosoft\MobilePush\Laravel\Contracts\QueueOptions;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;

/**
 * A send being prepared. Immutable: every method returns a modified copy, so a PendingSend can be kept and reused.
 *
 * Targets: typed destinations, notifiables (owner models with HasPushDevices, or any object with a
 * routeNotificationForPush() method returning typed destinations), raw tokens WITH their platform, topics and
 * conditions. Without a named connection each destination goes to the only route of its provider; registry
 * devices go to the connection they were registered on. Notifiables are resolved when send()/queue() runs, and
 * every registry device is checked again immediately before the network.
 */
final class PendingSend
{
    /** @var list<Destination|SendItem> */
    private array $destinations = [];

    /** @var list<object> */
    private array $notifiables = [];

    /** @var list<\Generator<mixed, Destination|SendItem>> lazy streams, only with a named connection */
    private array $streams = [];

    private bool $validateOnly = false;

    private TrafficClass $trafficClass = TrafficClass::Transactional;

    private ?string $effectKey = null;

    private ?\DateTimeImmutable $expiresAt = null;

    /** @var list<DestinationGate> */
    private array $gates = [];

    private ?string $queueConnection = null;

    private ?string $queue = null;

    private \DateTimeInterface|int|null $delay = null;

    private bool $afterCommit = true;

    public function __construct(
        private readonly PushManager $manager,
        private ?string $scope = null,
        private ?string $connection = null,
    ) {}

    // ---------------------------------------------------------------- where

    public function scope(string $scope): self
    {
        $clone = clone $this;
        $clone->scope = Scope::normalize($scope);

        return $clone;
    }

    /** Send through this connection only (a push connection: the queue connection is onQueueConnection()). */
    public function connection(string $name): self
    {
        $clone = clone $this;
        $clone->connection = $name;

        return $clone;
    }

    // ---------------------------------------------------------------- to whom

    /**
     * A typed Destination, a SendItem, an owner model / notifiable, or an iterable of those. A generator is kept
     * lazy (streamed to the provider) when a connection is named.
     */
    public function to(mixed $target): self
    {
        $clone = clone $this;
        $clone->add($target);

        return $clone;
    }

    /** A raw device token. The platform is mandatory: it is never inferred from the shape of the token. */
    public function toToken(Platform $platform, #[\SensitiveParameter] string $token): self
    {
        return $this->to(self::token($platform, $token));
    }

    /** @param  iterable<string>  $tokens */
    public function toTokens(Platform $platform, #[\SensitiveParameter] iterable $tokens): self
    {
        $clone = clone $this;
        foreach ($tokens as $token) {
            $clone->destinations[] = self::token($platform, $token);
        }

        return $clone;
    }

    public function toTopic(string $topic): self
    {
        return $this->to(FcmTopic::of($topic));
    }

    public function toCondition(string $condition): self
    {
        return $this->to(FcmCondition::of($condition));
    }

    // ---------------------------------------------------------------- how

    /** FCM validate_only: the provider validates the request without sending it (result Validated). */
    public function validateOnly(bool $validateOnly = true): self
    {
        $clone = clone $this;
        $clone->validateOnly = $validateOnly;

        return $clone;
    }

    /** Campaign / fan-out traffic: never uses the share reserved to transactional pushes. */
    public function bulk(): self
    {
        $clone = clone $this;
        $clone->trafficClass = TrafficClass::Bulk;

        return $clone;
    }

    /** One-to-one traffic the user is waiting for (default). */
    public function transactional(): self
    {
        $clone = clone $this;
        $clone->trafficClass = TrafficClass::Transactional;

        return $clone;
    }

    /** Logical effect key: deterministic provider message ids across retries (and dedup in the durable profile). */
    public function effectKey(?string $key): self
    {
        $clone = clone $this;
        $clone->effectKey = $key;

        return $clone;
    }

    /** Absolute logical expiry; overrides the message's own. */
    public function expiresAt(\DateTimeInterface $at): self
    {
        $clone = clone $this;
        $clone->expiresAt = \DateTimeImmutable::createFromInterface($at);

        return $clone;
    }

    /** An extra per-destination rule evaluated immediately before the network, after the built-in ones. */
    public function withGate(DestinationGate $gate): self
    {
        $clone = clone $this;
        $clone->gates[] = $gate;

        return $clone;
    }

    /** The QUEUE connection used by queue() (distinct from the push connection). */
    public function onQueueConnection(?string $connection): self
    {
        $clone = clone $this;
        $clone->queueConnection = $connection;

        return $clone;
    }

    public function onQueue(?string $queue): self
    {
        $clone = clone $this;
        $clone->queue = $queue;

        return $clone;
    }

    public function delay(\DateTimeInterface|int|null $delay): self
    {
        $clone = clone $this;
        $clone->delay = $delay;

        return $clone;
    }

    public function afterCommit(bool $afterCommit = true): self
    {
        $clone = clone $this;
        $clone->afterCommit = $afterCommit;

        return $clone;
    }

    // ---------------------------------------------------------------- go

    /**
     * Send now. When the targets span several connections (an iPhone and an Android phone), the report streams the
     * results of each connection in turn. No eligible destination = an empty report, not an error.
     * The report is a cursor: consume it (counts(), each(), results()).
     *
     * @throws ConfigurationException|InvalidDestinationException before the network
     */
    public function send(Message $message): SendReport
    {
        $scope = $this->resolvedScope();
        $reports = [];
        foreach ($this->plan($scope) as $group) {
            if ($group['binding'] === null) {
                $reports[] = $this->manager->mismatch($scope, $group['name'], null, $group['current'], $group['items'], $this->trafficClass);

                continue;
            }
            $binding = $group['binding'];
            $reports[] = $this->manager->send($binding, $message, $group['items'], $this->options($this->manager->defaultOptions($binding)));
        }

        return match (count($reports)) {
            0 => new SendReport([]),
            1 => $reports[0],
            default => new SendReport((static function () use ($reports): \Generator {
                foreach ($reports as $report) {
                    foreach ($report->results() as $result) {
                        yield $result;
                    }
                }
            })()),
        };
    }

    /**
     * Queue the send through the bound QueuedSender (the queue module). Returns the effect id; when the targets span
     * several connections, the effect ids of each connection joined by ",". The queue is chosen with
     * onQueueConnection() / onQueue() / delay() BEFORE calling queue().
     *
     * @throws ConfigurationException when no QueuedSender is bound
     */
    public function queue(Message $message): string
    {
        $queued = $this->manager->queuedSender();
        $scope = $this->resolvedScope();
        $groups = $this->plan($scope);
        $pending = $this;
        if ($this->effectKey === null && count($groups) > 1) {
            // One logical effect across connections.
            $pending = $this->effectKey((string) Str::ulid());
        }

        $ids = [];
        foreach ($groups as $group) {
            if ($group['binding'] === null) {
                $this->manager->mismatch($scope, $group['name'], null, $group['current'], $group['items'], $this->trafficClass)->count();

                continue;
            }
            $binding = $group['binding'];
            $destinations = (static function () use ($group): \Generator {
                foreach ($group['items'] as $item) {
                    yield $item instanceof SendItem ? $item->destination : $item;
                }
            })();
            $ids[] = $queued->queue(
                $binding,
                $message,
                $destinations,
                $pending->options($this->manager->defaultOptions($binding)),
                new QueueOptions($this->queueConnection, $this->queue, $this->delay, $this->afterCommit),
            );
        }

        return implode(',', $ids);
    }

    // ---------------------------------------------------------------- internals

    private function add(mixed $target): void
    {
        if ($target === null) {
            return;
        }
        if ($target instanceof Destination || $target instanceof SendItem) {
            $this->destinations[] = $target;

            return;
        }
        if (is_string($target)) {
            throw new InvalidDestinationException('A raw token needs its platform: use toToken(Platform::Ios|Platform::Android, $token). The platform is never guessed.');
        }
        if ($target instanceof \Generator) {
            $this->streams[] = $target;

            return;
        }
        if (is_object($target) && (method_exists($target, 'routeNotificationForPush') || method_exists($target, 'pushDevices'))) {
            $this->notifiables[] = $target;

            return;
        }
        if (is_iterable($target)) {
            foreach ($target as $item) {
                $this->add($item);
            }

            return;
        }

        throw new InvalidDestinationException(sprintf(
            'Cannot send a push to a %s: pass a typed destination (e.g. ApnsDeviceToken::of()), a model using HasPushDevices, an object with routeNotificationForPush(), or an iterable of those.',
            get_debug_type($target),
        ));
    }

    private function resolvedScope(): string
    {
        return $this->scope ?? $this->manager->currentScope();
    }

    /**
     * Destinations grouped by connection, in order of first appearance.
     *
     * @return list<array{name: string, binding: BindingRef|null, current: BindingRef|null, items: iterable<Destination|SendItem>}>
     *                                                                                                                              binding null = the group is a binding mismatch (registry rows of a binding that changed)
     */
    private function plan(string $scope): array
    {
        /** @var array<string, list<Destination|SendItem>> $groups */
        $groups = [];
        /** @var array<string, list<Destination|SendItem>> $mismatched */
        $mismatched = [];
        $named = $this->connection;

        $route = function (Destination|SendItem $item) use ($scope, $named): string {
            $destination = $item instanceof SendItem ? $item->destination : $item;
            $provider = $destination->kind()->provider();
            if ($named === null) {
                return $this->manager->routeFor($scope, $provider);
            }
            if ($this->manager->providerOf($scope, $named) !== $provider) {
                throw new InvalidDestinationException(sprintf(
                    'A %s destination cannot be sent through the %s connection "%s". Name a %s connection, or let the package route it.',
                    $destination->kind()->value, $this->manager->providerOf($scope, $named)->value, $named, $provider->value,
                ));
            }

            return $named;
        };

        foreach ($this->destinations as $item) {
            $groups[$route($item)][] = $item;
        }

        foreach ($this->notifiables as $notifiable) {
            foreach ($this->routedByNotifiable($notifiable) as $item) {
                $groups[$route($item)][] = $item;
            }
            foreach ($this->registryRows($notifiable, $scope, $named) as [$name, $identity, $destination]) {
                if ($this->identityOf($scope, $name) === $identity) {
                    $groups[$name][] = $destination;
                } else {
                    $mismatched[$name][] = $destination;
                }
            }
        }

        if ($this->streams !== [] && $named === null) {
            // Routing needs to see each destination: materialize the streams.
            foreach ($this->streams as $generator) {
                foreach ($generator as $item) {
                    $groups[$route($item)][] = $item;
                }
            }
        }

        $plan = [];
        $streamed = false;
        foreach ($groups as $name => $items) {
            $name = (string) $name;
            $stream = $items;
            if ($name === $named && $this->streams !== []) {
                $stream = $this->chain($items, $this->streams);
                $streamed = true;
            }
            $plan[] = ['name' => $name, 'binding' => $this->manager->binding($name, $scope), 'current' => null, 'items' => $stream];
        }
        if ($named !== null && $this->streams !== [] && ! $streamed) {
            $plan[] = ['name' => $named, 'binding' => $this->manager->binding($named, $scope), 'current' => null, 'items' => $this->chain([], $this->streams)];
        }

        foreach ($mismatched as $name => $items) {
            $name = (string) $name;
            $current = null;
            try {
                $current = $this->manager->binding($name, $scope);
            } catch (UnknownConnectionException) {
                // the name does not resolve any more
            }
            $plan[] = ['name' => $name, 'binding' => null, 'current' => $current, 'items' => $items];
        }

        return $plan;
    }

    /**
     * @param  list<Destination|SendItem>  $head
     * @param  list<\Generator<mixed, Destination|SendItem>>  $streams
     * @return \Generator<int, Destination|SendItem>
     */
    private function chain(array $head, array $streams): \Generator
    {
        yield from $head;
        foreach ($streams as $stream) {
            foreach ($stream as $item) {
                yield $item;
            }
        }
    }

    /** @return list<Destination|SendItem> */
    private function routedByNotifiable(object $notifiable): array
    {
        if (! method_exists($notifiable, 'routeNotificationForPush')) {
            return [];
        }
        $routed = $notifiable->routeNotificationForPush(null);
        if ($routed === null) {
            return [];
        }
        $out = [];
        foreach (is_iterable($routed) ? $routed : [$routed] as $item) {
            if (! $item instanceof Destination && ! $item instanceof SendItem) {
                throw new InvalidDestinationException(sprintf('%s::routeNotificationForPush() must return typed destinations (e.g. ApnsDeviceToken::of()), never raw strings.', $notifiable::class));
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Active registry rows of an owner in this scope: [connection name, binding identity, destination].
     *
     * @return list<array{0: string, 1: string, 2: Destination}>
     */
    private function registryRows(object $owner, string $scope, ?string $named): array
    {
        if (method_exists($owner, 'routeNotificationForPush') || ! method_exists($owner, 'pushDevices')) {
            return [];
        }
        if (! $this->manager->devicesEnabled()) {
            throw new ConfigurationException(sprintf('%s uses HasPushDevices but the device registry is disabled: set mobile-push.devices.enabled (MOBILE_PUSH_DEVICES_ENABLED=true) and run the migration.', $owner::class));
        }
        $relation = $owner->pushDevices();
        if (! $relation instanceof MorphMany) {
            throw new ConfigurationException(sprintf('%s::pushDevices() must be the relation provided by HasPushDevices.', $owner::class));
        }

        $query = $relation->getQuery()->where('state', 'active')->where('scope', $scope);
        if ($named !== null) {
            $query->where('binding_name', $named);
        } else {
            $query->whereIn('binding_name', $this->manager->routeNames($scope));
        }

        $rows = [];
        foreach ($query->orderBy('id')->get() as $device) {
            if ($device instanceof PushDevice) {
                $rows[] = [$device->binding_name, $device->binding_identity, $device->destination()];
            }
        }

        return $rows;
    }

    private function identityOf(string $scope, string $name): ?string
    {
        try {
            return $this->manager->binding($name, $scope)->identity();
        } catch (UnknownConnectionException) {
            return null;
        }
    }

    private function options(SendOptions $base): SendOptions
    {
        return new SendOptions(
            trafficClass: $this->trafficClass,
            validateOnly: $this->validateOnly,
            effectKey: $this->effectKey ?? $base->effectKey,
            applicationEnvironment: $base->applicationEnvironment,
            configuredMode: $base->configuredMode,
            mode: $base->mode,
            live: $base->live,
            retryPolicy: $base->retryPolicy,
            receiptMarker: $base->receiptMarker,
            gates: [...$base->gates, ...$this->gates],
            expiresAt: $this->expiresAt ?? $base->expiresAt,
            window: $base->window,
        );
    }

    private static function token(Platform $platform, #[\SensitiveParameter] string $token): Destination
    {
        return match ($platform) {
            Platform::Ios => ApnsDeviceToken::of($token),
            Platform::Android => FcmToken::of($token),
        };
    }
}
