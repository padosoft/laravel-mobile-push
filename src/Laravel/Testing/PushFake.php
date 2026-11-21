<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

use Illuminate\Support\Collection;
use Padosoft\MobilePush\Core\Driver\GateContext;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Outcome\Decision;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use PHPUnit\Framework\Assert;

/**
 * Verifies INTENT: what the application asked the package to send or queue.
 *
 * Nothing is compiled for the wire, nothing touches the network or the queue. Every send is recorded as
 * SentPush objects (one per destination) and answered with a scripted outcome (default: Accepted), through the real
 * manager, routing, gates and report, so application code that reads the report keeps working.
 *
 * It is deliberately NOT the oracle of the bytes: use MobilePush::fakeTransport() to check headers and payloads.
 */
final class PushFake
{
    /** @var list<SentPush> */
    private array $records = [];

    /** @var \WeakMap<Destination, SentPush> the record a destination object is waiting for its answer on */
    private \WeakMap $open;

    /** @var list<array{0: \Closure(SentPush): bool, 1: ScriptedOutcome|\Closure(SentPush): (SendStatus|ScriptedOutcome|null)}> */
    private array $rules = [];

    /** @var list<SendStatus|ScriptedOutcome|\Closure(SentPush): (SendStatus|ScriptedOutcome|null)> */
    private array $sequence = [];

    /** @var ScriptedOutcome|\Closure(SentPush): (SendStatus|ScriptedOutcome|null) */
    private ScriptedOutcome|\Closure $default;

    /** @var list<array{name: string, driver: string, appId: string|null, scope: string|null}> */
    private array $extraConnections = [];

    private bool $active = true;

    public function __construct()
    {
        $this->open = new \WeakMap;
        $this->default = ScriptedOutcome::accepted();
    }

    // ---------------------------------------------------------------- scripting

    /**
     * What every destination is answered with unless a more specific rule applies: a SendStatus, a ScriptedOutcome
     * or a callback fn (SentPush $push): SendStatus|ScriptedOutcome|null (null = Accepted).
     *
     * @param  SendStatus|ScriptedOutcome|callable(SentPush): (SendStatus|ScriptedOutcome|null)  $outcome
     */
    public function respondWith(SendStatus|ScriptedOutcome|callable $outcome): static
    {
        $this->default = self::outcome($outcome);

        return $this;
    }

    /**
     * Refuse the push to a destination (or a notifiable's destinations, or every push a predicate selects).
     * Default status Rejected. With ReasonCode::DestinationUnregistered the registry device is invalidated, as a
     * real APNs 410 would.
     *
     * @param  Destination|object|iterable<mixed>|callable(SentPush): bool  $target
     */
    public function failFor(mixed $target, ReasonCode $reason, SendStatus $status = SendStatus::Rejected): static
    {
        $outcome = match ($status) {
            SendStatus::Rejected => ScriptedOutcome::rejected($reason),
            SendStatus::Failed => ScriptedOutcome::failed($reason),
            SendStatus::Deferred => ScriptedOutcome::deferred(60, $reason),
            default => throw new \InvalidArgumentException('failFor() takes Rejected, Failed or Deferred; use unknownFor() for an unknown outcome and respondWith() for the rest.'),
        };
        $this->rules[] = [$this->matcherFor($target), $outcome];

        return $this;
    }

    /**
     * The provider answered nothing usable (connection lost after the request was written): OutcomeUnknown,
     * which is not a failure and must not be retried blindly.
     *
     * @param  Destination|object|iterable<mixed>|callable(SentPush): bool  $target
     */
    public function unknownFor(mixed $target): static
    {
        $this->rules[] = [$this->matcherFor($target), ScriptedOutcome::unknown()];

        return $this;
    }

    /**
     * Answers consumed in order, one per destination, after the failFor()/unknownFor() rules and before
     * respondWith(). When the list is exhausted the default applies again.
     *
     * @param  list<SendStatus|ScriptedOutcome|callable(SentPush): (SendStatus|ScriptedOutcome|null)>  $outcomes
     */
    public function sequence(array $outcomes): static
    {
        foreach ($outcomes as $outcome) {
            $this->sequence[] = self::outcome($outcome);
        }

        return $this;
    }

    /**
     * Declare a connection of your own (the fake knows `ios` and `android` when the application configures none).
     * Needed for a scope other than the current one, or a connection registered at runtime.
     *
     * @param  'apns'|'fcm'  $driver
     */
    public function connection(string $name, string $driver, ?string $appId = null, ?string $scope = null): static
    {
        $this->extraConnections[] = ['name' => $name, 'driver' => $driver, 'appId' => $appId, 'scope' => $scope];

        return $this;
    }

    // ---------------------------------------------------------------- reading

    /**
     * Pushes sent now (not queued), in order, optionally filtered.
     *
     * @param  (callable(SentPush): bool)|null  $filter
     * @return Collection<int, SentPush>
     */
    public function sent(?callable $filter = null): Collection
    {
        return $this->select(false, $filter);
    }

    /**
     * Pushes the application queued, in order, optionally filtered.
     *
     * @param  (callable(SentPush): bool)|null  $filter
     * @return Collection<int, SentPush>
     */
    public function queued(?callable $filter = null): Collection
    {
        return $this->select(true, $filter);
    }

    // ---------------------------------------------------------------- assertions: sent

    /**
     * A push matching a message class or a callback fn (SentPush $push): bool was sent (exactly `$times` of them
     * when given). Counts destinations: a message sent to two devices is two pushes.
     *
     * @param  callable(SentPush): bool|class-string  $messageClassOrCallback
     */
    public function assertSent(callable|string $messageClassOrCallback, ?int $times = null): void
    {
        $this->assertCount(false, $messageClassOrCallback, $times);
    }

    /** @param  callable(SentPush): bool|class-string  $messageClassOrCallback */
    public function assertNotSent(callable|string $messageClassOrCallback): void
    {
        $matches = $this->select(false, self::matcher($messageClassOrCallback));
        Assert::assertTrue($matches->isEmpty(), sprintf(
            "A push that must not be sent was sent %d time(s).\nMatching pushes:\n%s",
            $matches->count(), Describe::pushes($matches),
        ));
    }

    public function assertNothingSent(): void
    {
        $sent = $this->sent();
        Assert::assertTrue($sent->isEmpty(), sprintf("No push was expected, but %d were sent:\n%s", $sent->count(), Describe::pushes($sent)));
    }

    /** Exactly this many pushes (destinations) were sent now. */
    public function assertSentTimes(int $times): void
    {
        $sent = $this->sent();
        Assert::assertSame($times, $sent->count(), sprintf("Expected %d push(es) sent, %d were:\n%s", $times, $sent->count(), Describe::pushes($sent)));
    }

    /**
     * A push went to this destination, notifiable or owner model (any of its destinations), optionally matching a
     * callback fn (SentPush $push): bool.
     *
     * @param  Destination|object|iterable<mixed>  $notifiableOrDestination
     * @param  (callable(SentPush): bool)|null  $callback
     */
    public function assertSentTo(mixed $notifiableOrDestination, ?callable $callback = null): void
    {
        $targets = Targets::destinations($notifiableOrDestination);
        Assert::assertNotSame([], $targets, 'The notifiable resolves to no push destination (no routeNotificationForPush() result and no registered device), so nothing can be asserted for it.');

        $matches = $this->sent(static fn (SentPush $p): bool => Targets::contains($targets, $p->destination) && ($callback === null || $callback($p) === true));
        Assert::assertTrue($matches->isNotEmpty(), sprintf(
            "No matching push was sent to %s.\nPushes sent:\n%s",
            implode(', ', array_map(Describe::destination(...), $targets)), Describe::pushes($this->sent()),
        ));
    }

    public function assertSentOnConnection(string $connection): void
    {
        $this->assertAny($this->sent(static fn (SentPush $p): bool => $p->connection === $connection), sprintf('No push was sent on the connection "%s".', $connection));
    }

    public function assertSentInScope(string $scope): void
    {
        $this->assertAny($this->sent(static fn (SentPush $p): bool => $p->scope === $scope), sprintf('No push was sent in the scope "%s".', $scope));
    }

    public function assertSentToTopic(string $topic): void
    {
        $this->assertAny(
            $this->sent(static fn (SentPush $p): bool => $p->destination->kind() === DestinationKind::FcmTopic && $p->destination->value() === $topic),
            sprintf('No push was sent to the FCM topic "%s".', $topic),
        );
    }

    // ---------------------------------------------------------------- assertions: queued

    /** @param  callable(SentPush): bool|class-string|null  $messageClassOrCallback  null = anything */
    public function assertQueued(callable|string|null $messageClassOrCallback = null, ?int $times = null): void
    {
        $this->assertCount(true, $messageClassOrCallback ?? static fn (): bool => true, $times);
    }

    /** @param  callable(SentPush): bool|class-string|null  $messageClassOrCallback  null = nothing at all was queued */
    public function assertNotQueued(callable|string|null $messageClassOrCallback = null): void
    {
        $matches = $this->select(true, $messageClassOrCallback === null ? null : self::matcher($messageClassOrCallback));
        Assert::assertTrue($matches->isEmpty(), sprintf("No push should have been queued, but %d were:\n%s", $matches->count(), Describe::pushes($matches)));
    }

    // ---------------------------------------------------------------- pipeline hooks (internal)

    /** @internal Records a push the application asked for. */
    public function record(SentPush $push): void
    {
        if (! $this->active) {
            return;
        }
        $this->records[] = $push;
        if (! $push->queued) {
            $this->open[$push->destination] = $push;
        }
    }

    /**
     * @internal The destination gate: sees every destination right before the (fake) network, once per window.
     *
     * @param  list<SendItem>  $items
     * @return array<int, never>
     */
    public function capture(GateContext $context, array $items): array
    {
        if ($this->active) {
            $options = new SentOptions(false, $context->trafficClass, $context->effectKey);
            foreach ($items as $item) {
                $this->record(new SentPush($context->binding, $item->destination, $context->message, $options));
            }
        }

        return [];
    }

    /** @internal Fills in what only the pipeline knows (the pinned expiry, validate-only). */
    public function pinned(Destination $destination, ?\DateTimeImmutable $expiresAt, bool $validateOnly): void
    {
        $push = $this->open[$destination] ?? null;
        if ($push !== null) {
            $push->options = new SentOptions($validateOnly, $push->options->trafficClass, $push->options->effectKey, $expiresAt);
        }
    }

    /** @internal The scripted answer for one destination of the current attempt. */
    public function decide(Destination $destination, string $requestId): Decision
    {
        $push = $this->open[$destination] ?? null;
        $outcome = $push === null ? ScriptedOutcome::accepted() : $this->outcomeFor($push);
        $messageId = self::uuid($requestId.spl_object_id($destination));
        $decision = $outcome->toDecision($outcome->status === SendStatus::Accepted ? $messageId : null);

        if ($push !== null) {
            $push->result = new SendResult(
                $destination,
                $outcome->status,
                $decision->reason,
                1,
                $decision->providerMessageId,
                $decision->httpStatus,
                $outcome->status === SendStatus::Deferred ? (new \DateTimeImmutable)->modify('+'.$outcome->delaySeconds.' seconds') : null,
                $decision->destinationAction,
            );
        }

        return $decision;
    }

    /** @internal */
    public function deactivate(): void
    {
        $this->active = false;
    }

    /** @return list<array{name: string, driver: string, appId: string|null, scope: string|null}> @internal */
    public function extraConnections(): array
    {
        return $this->extraConnections;
    }

    /** @internal */
    public static function provider(string $driver): Provider
    {
        return $driver === 'fcm' ? Provider::Fcm : Provider::Apns;
    }

    // ---------------------------------------------------------------- internals

    private function outcomeFor(SentPush $push): ScriptedOutcome
    {
        foreach ($this->rules as [$matches, $outcome]) {
            if ($matches($push)) {
                return $this->resolve($outcome, $push);
            }
        }
        if ($this->sequence !== []) {
            return $this->resolve(array_shift($this->sequence), $push);
        }

        return $this->resolve($this->default, $push);
    }

    /** @param  ScriptedOutcome|\Closure(SentPush): (SendStatus|ScriptedOutcome|null)  $outcome */
    private function resolve(ScriptedOutcome|\Closure $outcome, SentPush $push): ScriptedOutcome
    {
        if ($outcome instanceof ScriptedOutcome) {
            return $outcome;
        }
        $answer = $outcome($push);

        return match (true) {
            $answer === null => ScriptedOutcome::accepted(),
            $answer instanceof SendStatus => ScriptedOutcome::fromStatus($answer),
            default => $answer,
        };
    }

    /**
     * @param  SendStatus|ScriptedOutcome|callable(SentPush): (SendStatus|ScriptedOutcome|null)  $outcome
     * @return ScriptedOutcome|\Closure(SentPush): (SendStatus|ScriptedOutcome|null)
     */
    private static function outcome(SendStatus|ScriptedOutcome|callable $outcome): ScriptedOutcome|\Closure
    {
        return match (true) {
            $outcome instanceof SendStatus => ScriptedOutcome::fromStatus($outcome),
            $outcome instanceof ScriptedOutcome => $outcome,
            default => \Closure::fromCallable($outcome),
        };
    }

    /** @return \Closure(SentPush): bool */
    private function matcherFor(mixed $target): \Closure
    {
        if ($target instanceof \Closure) {
            return static fn (SentPush $p): bool => $target($p) === true;
        }
        $destinations = Targets::destinations($target);
        if ($destinations === []) {
            throw new \InvalidArgumentException('The target resolves to no push destination: pass a typed destination, a notifiable with routeNotificationForPush() / registered devices, or a predicate.');
        }

        return static fn (SentPush $p): bool => Targets::contains($destinations, $p->destination);
    }

    /**
     * @param  callable(SentPush): bool|class-string  $matcher
     * @return \Closure(SentPush): bool
     */
    private static function matcher(callable|string $matcher): \Closure
    {
        if (is_string($matcher)) {
            if (! class_exists($matcher) && ! interface_exists($matcher)) {
                throw new \InvalidArgumentException(sprintf('"%s" is not a class: pass a message class name or a callback.', $matcher));
            }

            return static fn (SentPush $p): bool => $p->message instanceof $matcher;
        }

        return static fn (SentPush $p): bool => $matcher($p) === true;
    }

    /**
     * @param  (callable(SentPush): bool)|null  $filter
     * @return Collection<int, SentPush>
     */
    private function select(bool $queued, ?callable $filter): Collection
    {
        return (new Collection($this->records))
            ->filter(static fn (SentPush $p): bool => $p->queued === $queued && ($filter === null || $filter($p) === true))
            ->values();
    }

    /** @param  callable(SentPush): bool|class-string  $matcher */
    private function assertCount(bool $queued, callable|string $matcher, ?int $times): void
    {
        $matches = $this->select($queued, self::matcher($matcher));
        $kind = $queued ? 'queued' : 'sent';
        $label = is_string($matcher) ? $matcher : 'the expected push';
        if ($times === null) {
            Assert::assertTrue($matches->isNotEmpty(), sprintf("%s was not %s.\nPushes %s:\n%s", ucfirst($label), $kind, $kind, Describe::pushes($this->select($queued, null))));

            return;
        }
        Assert::assertSame($times, $matches->count(), sprintf(
            "%s was expected %d time(s) %s, but %d matched.\nPushes %s:\n%s",
            ucfirst($label), $times, $kind, $matches->count(), $kind, Describe::pushes($this->select($queued, null)),
        ));
    }

    /** @param  Collection<int, SentPush>  $matches */
    private function assertAny(Collection $matches, string $message): void
    {
        Assert::assertTrue($matches->isNotEmpty(), $message."\nPushes sent:\n".Describe::pushes($this->sent()));
    }

    private static function uuid(string $seed): string
    {
        $h = hash('sha256', $seed);

        return sprintf('%s-%s-4%s-8%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
    }
}
