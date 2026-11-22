<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Padosoft\MobilePush\Laravel\Testing\FakeTransportBuilder;
use Padosoft\MobilePush\Laravel\Testing\PushFake;
use Padosoft\MobilePush\Laravel\Testing\TestingSession;

/**
 * Facade of the push manager.
 *
 * Testing: fake() verifies INTENT (what your code asked to send or queue; assertSent(), assertSentTo(),
 * assertNothingSent(), assertQueued() ...), fakeTransport() verifies BYTES and behaviour (the real pipeline runs, only
 * the transport is replaced; assertApnsRequest(), assertFcmRequest() ...). Both work without any credentials.
 *
 * @method static void assertSent((callable(\Padosoft\MobilePush\Laravel\Testing\SentPush): bool)|class-string $messageClassOrCallback, ?int $times = null) Intent fake: see PushFake::assertSent(); needs MobilePush::fake() first
 * @method static void assertNotSent((callable(\Padosoft\MobilePush\Laravel\Testing\SentPush): bool)|class-string $messageClassOrCallback) Intent fake
 * @method static void assertNothingSent() Intent fake
 * @method static void assertSentTimes(int $times) Intent fake
 * @method static void assertSentTo(mixed $notifiableOrDestination, (callable(\Padosoft\MobilePush\Laravel\Testing\SentPush): bool)|null $callback = null) Intent fake
 * @method static void assertSentOnConnection(string $connection) Intent fake
 * @method static void assertSentInScope(string $scope) Intent fake
 * @method static void assertSentToTopic(string $topic) Intent fake
 * @method static void assertQueued((callable(\Padosoft\MobilePush\Laravel\Testing\SentPush): bool)|class-string|null $messageClassOrCallback = null, ?int $times = null) Intent fake
 * @method static void assertNotQueued((callable(\Padosoft\MobilePush\Laravel\Testing\SentPush): bool)|class-string|null $messageClassOrCallback = null) Intent fake
 * @method static \Illuminate\Support\Collection<int, \Padosoft\MobilePush\Laravel\Testing\SentPush> sent((callable(\Padosoft\MobilePush\Laravel\Testing\SentPush): bool)|null $filter = null) Intent fake: pushes sent now
 * @method static \Illuminate\Support\Collection<int, \Padosoft\MobilePush\Laravel\Testing\SentPush> queued((callable(\Padosoft\MobilePush\Laravel\Testing\SentPush): bool)|null $filter = null) Intent fake: pushes queued
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend connection(?string $name = null)
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend scope(string $scope)
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend to(mixed $target)
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend toToken(\Padosoft\MobilePush\Core\Identity\Platform $platform, string $token)
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend toTokens(\Padosoft\MobilePush\Core\Identity\Platform $platform, iterable<string> $tokens)
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend toTopic(string $topic)
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend toCondition(string $condition)
 * @method static void register(string $name, array<mixed> $config, ?string $scope = null)
 * @method static void resolveConnectionUsing(callable(string, string): (array<mixed>|null) $resolver)
 * @method static void scopeUsing((callable(): string)|null $resolver) Supply the current scope; null restores the default (`mobile-push.scope`)
 * @method static void forgetScopeResolver() Undo scopeUsing() (same as scopeUsing(null)); use it to reset per-request state under Octane
 * @method static void forgetConnectionResolver() Undo resolveConnectionUsing(); cached drivers are kept until forgetConnection()/flush()
 * @method static \Padosoft\MobilePush\Core\Fcm\Topics\TopicSubscriptionClient topicSubscriptions(?string $connection = null) Topic subscription client of an FCM connection, behind the same safe-mode gate as a send
 * @method static void eligibleUsing(callable(mixed, mixed): mixed $check, bool $batch = false)
 * @method static string currentScope()
 * @method static list<string> connectionNames(?string $scope = null)
 * @method static void forgetConnection(string $name, ?string $scope = null)
 * @method static void flush()
 * @method static int cachedConnections()
 * @method static \Padosoft\MobilePush\Core\Identity\BindingRef binding(string $connection, ?string $scope = null)
 * @method static \Padosoft\MobilePush\Core\Outcome\SendReport send(\Padosoft\MobilePush\Core\Identity\BindingRef $binding, \Padosoft\MobilePush\Core\Message\Message $message, iterable<\Padosoft\MobilePush\Core\Identity\Destination|\Padosoft\MobilePush\Core\Driver\SendItem> $destinations, ?\Padosoft\MobilePush\Core\Driver\SendOptions $options = null)
 * @method static \Padosoft\MobilePush\Core\Driver\Driver driver(\Padosoft\MobilePush\Core\Identity\BindingRef $binding)
 * @method static \Padosoft\MobilePush\Core\Driver\SendOptions defaultOptions(\Padosoft\MobilePush\Core\Identity\BindingRef $binding)
 * @method static \Padosoft\MobilePush\Laravel\Campaigns\CampaignBuilder campaign(\Padosoft\MobilePush\Core\Message\Message $message) A campaign to a large audience (campaigns module, durable profile)
 * @method static void unregister(string $name, ?string $scope = null) Undo register(): the runtime connection is removed and its cached driver dropped
 * @method static int forgetEligibility(callable(mixed, mixed): mixed $check) Undo eligibleUsing() for this very callable; returns how many checks were removed
 * @method static int eligibilityChecks() Number of checks registered with eligibleUsing()
 * @method static void useDriver(string $name, \Padosoft\MobilePush\Core\Driver\Driver $driver, ?string $scope = null, ?\Padosoft\MobilePush\Core\Policy\LiveSettings $live = null) Extension/testing seam: send through this driver for the connection (wins over runtime, resolver and config)
 * @method static void forgetDriver(string $name, ?string $scope = null) Remove a driver installed with useDriver()
 * @method static bool hasInstalledDriver(string $name, ?string $scope = null) Whether useDriver() installed a driver for the connection
 * @method static void resetBadge(object $owner, ?string $connection = null, ?string $scope = null) Reset the server-side badge counter of an owner to 0 (policies module)
 * @method static int currentBadge(object $owner, ?string $connection = null, ?string $scope = null) The current server-side badge number of an owner
 * @method static \Padosoft\MobilePush\Laravel\Sending\SendingSettings settings() The sending settings read from `mobile-push.*`
 * @method static void forceExecution(?\Padosoft\MobilePush\Core\Policy\ExecutionMode $mode, ?\Padosoft\MobilePush\Core\Policy\LiveSettings $live = null, bool $everyDestination = false) @internal testing seam: force the execution mode of every send (never the application environment); null clears it
 * @method static bool executionForced() @internal whether forceExecution() is in effect
 * @method static \Padosoft\MobilePush\Core\Policy\Badge\BadgeResolver|null badgeResolver() @internal
 * @method static \Padosoft\MobilePush\Laravel\Sending\ResolvedConnection resolve(string $scope, string $name) @internal
 * @method static \Padosoft\MobilePush\Core\Identity\Provider providerOf(string $scope, string $name) @internal
 * @method static string routeFor(string $scope, \Padosoft\MobilePush\Core\Identity\Provider $provider) @internal
 * @method static list<string> routeNames(string $scope) @internal
 * @method static bool devicesEnabled() @internal
 * @method static \Padosoft\MobilePush\Laravel\Contracts\QueuedSender queuedSender() @internal
 * @method static \Padosoft\MobilePush\Core\Exceptions\ValidationException|null preflight(\Padosoft\MobilePush\Core\Identity\BindingRef $binding, \Padosoft\MobilePush\Core\Message\Message $message, \Padosoft\MobilePush\Core\Identity\Destination $sample, bool $validateOnly = false) @internal
 * @method static \Padosoft\MobilePush\Core\Outcome\SendReport refused(\Padosoft\MobilePush\Core\Identity\BindingRef $binding, \Padosoft\MobilePush\Core\Exceptions\ValidationException $error, iterable<\Padosoft\MobilePush\Core\Identity\Destination|\Padosoft\MobilePush\Core\Driver\SendItem> $destinations, \Padosoft\MobilePush\Core\Guard\TrafficClass $trafficClass) @internal
 * @method static \Padosoft\MobilePush\Core\Outcome\SendReport mismatch(string $scope, string $name, ?\Padosoft\MobilePush\Core\Identity\BindingRef $expected, ?\Padosoft\MobilePush\Core\Identity\BindingRef $current, iterable<\Padosoft\MobilePush\Core\Identity\Destination|\Padosoft\MobilePush\Core\Driver\SendItem> $destinations, \Padosoft\MobilePush\Core\Guard\TrafficClass $trafficClass) @internal
 *
 * @see PushManager
 */
final class MobilePush extends Facade
{
    /**
     * Switch to the intent fake: nothing is sent or queued; every send and queue request is recorded and answered
     * with a scripted outcome (default Accepted). The real compilers still validate each message (nothing kept; opt
     * out with ->withoutValidation()). Sends run live with every destination allowed through the manager's forced
     * execution; the application environment is never changed. Throws FakeNotAllowedException outside the
     * `testing` environment unless `$allowOutsideTesting`.
     */
    public static function fake(bool $allowOutsideTesting = false): PushFake
    {
        return self::testing()->fake($allowOutsideTesting);
    }

    /**
     * Keep the real pipeline (compilers, safe-mode guard, guards, classifier, retry planner, events, log sinks,
     * device registry) and replace only the Transport. Forces live mode with every destination allowed
     * (PushManager::forceExecution(), the application environment is untouched), because outside production the
     * default mode simulates and would never reach the transport.
     */
    public static function fakeTransport(bool $allowOutsideTesting = false): FakeTransportBuilder
    {
        return self::testing()->fakeTransport($allowOutsideTesting);
    }

    /** Assertion and inspection methods of the intent fake that can be called statically on the facade. */
    private const FAKE_METHODS = [
        'assertsent', 'assertnotsent', 'assertnothingsent', 'assertsenttimes', 'assertsentto', 'assertsentonconnection',
        'assertsentinscope', 'assertsenttotopic', 'assertqueued', 'assertnotqueued', 'sent', 'queued',
    ];

    /**
     * Static assertions (`MobilePush::assertSent(...)`) go to the intent fake that is active for this application;
     * every other static call goes to the manager as usual. The manager itself knows nothing about tests.
     *
     * @param  string  $method
     * @param  array<mixed>  $args
     *
     * @throws \LogicException when one of those methods is called and MobilePush::fake() is not active
     */
    public static function __callStatic($method, $args): mixed
    {
        if (! in_array(strtolower($method), self::FAKE_METHODS, true)) {
            return parent::__callStatic($method, $args);
        }

        $fake = self::testing()->intent();
        if ($fake === null) {
            throw new \LogicException(sprintf(
                'MobilePush::%s() needs the intent fake, and none is active: call MobilePush::fake() first (MobilePush::fakeTransport() has its own assertions, on the object it returns).',
                $method,
            ));
        }

        return $fake->{$method}(...$args);
    }

    private static function testing(): TestingSession
    {
        $app = self::getFacadeApplication();
        if ($app === null) {
            throw new \RuntimeException('The MobilePush facade has no application: boot the application before activating a fake.');
        }

        return $app->make(TestingSession::class);
    }

    protected static function getFacadeAccessor(): string
    {
        return PushManager::class;
    }
}
