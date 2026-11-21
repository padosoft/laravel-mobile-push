<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Padosoft\MobilePush\Core\Driver\DestinationGate;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Driver\SendOptions;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Exceptions\UnknownConnectionException;
use Padosoft\MobilePush\Core\Exceptions\ValidationException;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmCondition;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Identity\Scope;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendReport;
use Padosoft\MobilePush\Core\Policy\Frequency\Subject;
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
 *
 * With a named connection, a notifiable is NARROWED to what that connection carries (its registry rows of that
 * connection, its routed destinations of that provider); an explicit destination of the other provider is an
 * InvalidDestinationException; a registry destination of another binding is Skipped(BindingMismatch) by the
 * eligibility gate, before the network.
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

    private ?Notification $notification = null;

    private ?Provider $onlyProvider = null;

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

    /**
     * The notification being sent, handed to the notifiables' routeNotificationForPush($notification) /
     * routeNotificationFor('push', $notification) when they are resolved (Laravel's routing convention).
     */
    public function forNotification(?Notification $notification): self
    {
        $clone = clone $this;
        $clone->notification = $notification;

        return $clone;
    }

    /**
     * Send only to destinations of one provider: destinations and registry devices of the other provider are left
     * out (not reported as skipped), a named connection of the other provider sends nothing.
     */
    public function onlyProvider(?Provider $provider): self
    {
        $clone = clone $this;
        $clone->onlyProvider = $provider;

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
     * Send now (the synchronous path). When the targets span several connections (an iPhone and an Android phone),
     * the report streams the results of each connection in turn. No eligible destination = an empty report, not an
     * error. The report is a cursor: consume it (counts(), each(), results()).
     *
     * Every send has an effect key (the caller's, or one generated for this call), shared by all its connections, so
     * the frequency cap and the badge counter decide once per send. A PushMessage with incrementBadge() sent to
     * registry devices gets ONE badge number per owner (every device of the owner shows the same number).
     *
     * Durable profile: send() is synchronous and never writes the ledger (no effect row, no outbox, no receipt
     * marker); only queue() goes through the ledger. Use queue() when the push must survive a crash.
     *
     * Nothing is sent until every connection of the send has passed its pre-flight (the message compiled against
     * one destination of each connection, no network). A message that is invalid for SOME connections only (a
     * subtitle reaches iOS but has no Android equivalent) concerns only their destinations: they get Rejected
     * results (PayloadInvalid, the compiler's message as hint) and the other connections send normally. A message
     * invalid for EVERY connection throws, before any request.
     *
     * @throws ConfigurationException|InvalidDestinationException before the network
     * @throws ValidationException when the message is invalid for every connection of the send
     */
    public function send(Message $message): SendReport
    {
        $scope = $this->resolvedScope();
        $pending = $this->effectKey === null ? $this->effectKey((string) Str::ulid()) : $this;
        $plan = $this->plan($scope);
        $refused = $this->preflight($plan, $message);
        $reports = [];
        foreach ($plan as $index => $group) {
            if ($group['binding'] === null) {
                $reports[] = $this->manager->mismatch($scope, $group['name'], null, $group['current'], $group['items'], $this->trafficClass);

                continue;
            }
            $binding = $group['binding'];
            if (isset($refused[$index])) {
                $reports[] = $this->manager->refused($binding, $refused[$index], $group['items'], $this->trafficClass);

                continue;
            }
            foreach ($pending->badged($binding, $message, $group['items'], false) as [$groupMessage, $items, $key]) {
                $reports[] = $this->manager->send($binding, $groupMessage, $items, $pending->effectKey($key)->options($this->manager->defaultOptions($binding)));
            }
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
     * Validated before anything is queued, with the same pre-flight as send(): a connection whose message cannot
     * compile queues nothing and writes nothing to the ledger; its destinations are recorded as Rejected now (send
     * log, events) and it contributes no effect id. A message invalid for every connection throws before anything
     * is queued.
     *
     * @throws ConfigurationException when no QueuedSender is bound
     * @throws ValidationException when the message is invalid for every connection of the send
     */
    public function queue(Message $message): string
    {
        $queued = $this->manager->queuedSender();
        $scope = $this->resolvedScope();
        $groups = $this->plan($scope);
        $refused = $this->preflight($groups, $message);
        $pending = $this;
        if ($this->effectKey === null && (count($groups) > 1 || $this->needsBadge($message))) {
            // One logical effect across connections (and a stable key for the badge decision).
            $pending = $this->effectKey((string) Str::ulid());
        }

        $ids = [];
        foreach ($groups as $index => $group) {
            if ($group['binding'] === null) {
                $this->manager->mismatch($scope, $group['name'], null, $group['current'], $group['items'], $this->trafficClass)->count();

                continue;
            }
            $binding = $group['binding'];
            if (isset($refused[$index])) {
                // Nothing queued, nothing written to the ledger: the destinations are recorded as Rejected now.
                $this->manager->refused($binding, $refused[$index], $group['items'], $this->trafficClass)->count();

                continue;
            }
            foreach ($pending->badged($binding, $message, $group['items'], true) as [$groupMessage, $items, $key]) {
                $destinations = (static function () use ($items): \Generator {
                    foreach ($items as $item) {
                        yield $item instanceof SendItem ? $item->destination : $item;
                    }
                })();
                $ids[] = $queued->queue(
                    $binding,
                    $groupMessage,
                    $destinations,
                    $pending->effectKey($key)->options($this->manager->defaultOptions($binding)),
                    new QueueOptions($this->queueConnection, $this->queue, $this->delay, $this->afterCommit),
                );
            }
        }

        return implode(',', $ids);
    }

    // ---------------------------------------------------------------- internals

    /**
     * Pre-flight of every connection of the send BEFORE the first one touches the network or the queue: the message
     * is compiled once per connection against its first destination (no receipt marker, nothing sent). A connection
     * whose destinations arrive as a lazy stream (a named connection fed by a generator) is not sampled: its Sender
     * still raises a message-level error before its first request.
     *
     * @param  list<array{name: string, binding: BindingRef|null, current: BindingRef|null, items: iterable<Destination|SendItem>}>  $plan
     * @return array<int, ValidationException> plan index => the error of a connection whose message is invalid
     *
     * @throws ValidationException when the message is invalid for every connection of the send (nothing was sent)
     */
    private function preflight(array $plan, Message $message): array
    {
        $refused = [];
        $checked = 0;
        foreach ($plan as $index => $group) {
            if ($group['binding'] === null || ! is_array($group['items']) || $group['items'] === []) {
                continue;
            }
            $first = $group['items'][array_key_first($group['items'])];
            $error = $this->manager->preflight($group['binding'], $message, $first instanceof SendItem ? $first->destination : $first, $this->validateOnly);
            $checked++;
            if ($error !== null) {
                $refused[$index] = $error;
            }
        }
        if ($refused !== [] && count($refused) === $checked && ! $this->hasUncheckedGroup($plan)) {
            throw $refused[array_key_first($refused)];
        }

        return $refused;
    }

    /** @param  list<array{name: string, binding: BindingRef|null, current: BindingRef|null, items: iterable<Destination|SendItem>}>  $plan */
    private function hasUncheckedGroup(array $plan): bool
    {
        foreach ($plan as $group) {
            if ($group['binding'] !== null && ! is_array($group['items'])) {
                return true;
            }
        }

        return false;
    }

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
        if (is_object($target) && (method_exists($target, 'routeNotificationForPush') || method_exists($target, 'pushDevices') || method_exists($target, 'routeNotificationFor'))) {
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

        $only = $this->onlyProvider;
        $keep = static function (Destination|SendItem $item) use ($only): bool {
            $destination = $item instanceof SendItem ? $item->destination : $item;

            return $only === null || $destination->kind()->provider() === $only;
        };
        if ($only !== null && $named !== null && $this->manager->providerOf($scope, $named) !== $only) {
            return [];   // the named connection is of the other provider: nothing to send
        }

        foreach ($this->destinations as $item) {
            if ($keep($item)) {
                $groups[$route($item)][] = $item;
            }
        }

        // A notifiable is narrowed by a named connection, never refused by it: its routed destinations of the other
        // provider are left out (as its registry rows of other connections are), so to($users) through one
        // connection never fails because a user also has a phone of the other platform. Destinations passed
        // explicitly stay strict (InvalidDestinationException in $route).
        $namedProvider = $named === null ? null : $this->manager->providerOf($scope, $named);
        foreach ($this->notifiables as $notifiable) {
            foreach ($this->routedByNotifiable($notifiable) as $item) {
                $destination = $item instanceof SendItem ? $item->destination : $item;
                if ($namedProvider !== null && $destination->kind()->provider() !== $namedProvider) {
                    continue;
                }
                if ($keep($item)) {
                    $groups[$route($item)][] = $item;
                }
            }
            foreach ($this->registryRows($notifiable, $scope, $named) as [$name, $identity, $destination]) {
                if (! $keep($destination)) {
                    continue;
                }
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
                    if ($keep($item)) {
                        $groups[$route($item)][] = $item;
                    }
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
                $destination = $item instanceof SendItem ? $item->destination : $item;
                if ($this->onlyProvider !== null && $destination->kind()->provider() !== $this->onlyProvider) {
                    continue;
                }
                yield $item;
            }
        }
    }

    private function needsBadge(Message $message): bool
    {
        return $message instanceof PushMessage && $message->incrementBadge && $message->badge === null;
    }

    /**
     * The sub-sends of one connection group: one per owner when the message asks the server to increment the badge
     * (each owner gets its own resolved number, the same on every device of that owner and on every retry of the
     * effect), otherwise the group itself. Destinations without an owner keep the message as it is.
     *
     * @param  iterable<Destination|SendItem>  $items
     * @param  bool  $distinctKeys  queue(): give each owner sub-group its own effect key (one ledger effect per
     *                              message content); send(): keep the one key of the send
     * @return list<array{0: Message, 1: iterable<Destination|SendItem>, 2: string|null}>
     */
    private function badged(BindingRef $binding, Message $message, iterable $items, bool $distinctKeys): array
    {
        $resolver = $this->needsBadge($message) && is_array($items) ? $this->manager->badgeResolver() : null;
        if ($resolver === null || ! $message instanceof PushMessage) {
            return [[$message, $items, $this->effectKey]];
        }

        /** @var array<string, list<Destination|SendItem>> $byOwner */
        $byOwner = [];
        $ownerless = [];
        foreach ($items as $item) {
            $ref = ($item instanceof SendItem ? $item->destination : $item)->ref();
            if ($ref !== null && $ref->ownerType !== null && $ref->ownerId !== null) {
                $byOwner[Subject::owner($ref->ownerType, $ref->ownerId)][] = $item;
            } else {
                $ownerless[] = $item;
            }
        }

        $out = [];
        if ($ownerless !== []) {
            $out[] = [$message, $ownerless, $this->effectKey];
        }
        $split = $distinctKeys && count($byOwner) + ($ownerless === [] ? 0 : 1) > 1;
        foreach ($byOwner as $subject => $owned) {
            $subject = (string) $subject;
            // An owner nobody will receive the push for (every device refused by the registry or the application's
            // eligibility checks) does not consume an increment: it keeps the unresolved message and the send reports
            // its devices as skipped.
            $resolved = $this->manager->anyDestinationEligible($binding, $message, $owned, $this->trafficClass, $this->effectKey)
                ? $resolver->resolve($message, $binding->scope, $subject, $this->effectKey, $binding)
                : $message;
            $key = $split && $this->effectKey !== null ? $this->effectKey.'/'.substr(hash('sha256', $subject), 0, 16) : $this->effectKey;
            $out[] = [$resolved, $owned, $key];
        }

        return $out;
    }

    /** @return list<Destination|SendItem> */
    private function routedByNotifiable(object $notifiable): array
    {
        if (method_exists($notifiable, 'routeNotificationForPush')) {
            $routed = $notifiable->routeNotificationForPush($this->notification);
        } elseif (! method_exists($notifiable, 'pushDevices') && method_exists($notifiable, 'routeNotificationFor')) {
            // Notification::route('push', ...) (AnonymousNotifiable) and Notifiable models without a push route.
            $routed = $notifiable->routeNotificationFor('push', $this->notification);
        } else {
            return [];
        }
        if ($routed === null || $routed === []) {
            return [];
        }
        $out = [];
        foreach (is_iterable($routed) ? $routed : [$routed] as $item) {
            if (! $item instanceof Destination && ! $item instanceof SendItem) {
                throw new InvalidDestinationException(sprintf('The push route of %s must return typed destinations (e.g. ApnsDeviceToken::of()), never raw strings.', $notifiable::class));
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

    /**
     * The send options of this pending send on top of the connection's defaults: every field of the base (mode,
     * live settings, retry policy, receipt marker, window, gates, per-call observers) is carried over; only what was
     * set here replaces it, and the gates added with withGate() run after the built-in ones.
     */
    private function options(SendOptions $base): SendOptions
    {
        $options = $base
            ->withTrafficClass($this->trafficClass)
            ->withValidateOnly($this->validateOnly)
            ->withEffectKey($this->effectKey ?? $base->effectKey)
            ->withExpiresAt($this->expiresAt ?? $base->expiresAt);
        foreach ($this->gates as $gate) {
            $options = $options->withGate($gate);
        }

        return $options;
    }

    private static function token(Platform $platform, #[\SensitiveParameter] string $token): Destination
    {
        return match ($platform) {
            Platform::Ios => ApnsDeviceToken::of($token),
            Platform::Android => FcmToken::of($token),
        };
    }
}
