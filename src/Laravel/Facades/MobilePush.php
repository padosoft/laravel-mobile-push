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
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend connection(?string $name = null)
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend scope(string $scope)
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend to(mixed $target)
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend toToken(\Padosoft\MobilePush\Core\Identity\Platform $platform, string $token)
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend toTokens(\Padosoft\MobilePush\Core\Identity\Platform $platform, iterable<string> $tokens)
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend toTopic(string $topic)
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend toCondition(string $condition)
 * @method static void register(string $name, array<mixed> $config, ?string $scope = null)
 * @method static void resolveConnectionUsing(callable(string, string): (array<mixed>|null) $resolver)
 * @method static void scopeUsing(callable(): string $resolver)
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
 *
 * @see PushManager
 */
final class MobilePush extends Facade
{
    /**
     * Switch to the intent fake: nothing is compiled, sent or queued; every send and queue request is recorded
     * and answered with a scripted outcome (default Accepted). Throws FakeNotAllowedException outside the
     * `testing` environment unless `$allowOutsideTesting`.
     */
    public static function fake(bool $allowOutsideTesting = false): PushFake
    {
        return self::testing()->fake($allowOutsideTesting);
    }

    /**
     * Keep the real pipeline (compilers, safe-mode guard, guards, classifier, retry planner, events, log sinks,
     * device registry) and replace only the Transport. Forces live mode with every destination allowed, because
     * outside production the default mode simulates and would never reach the transport.
     */
    public static function fakeTransport(bool $allowOutsideTesting = false): FakeTransportBuilder
    {
        return self::testing()->fakeTransport($allowOutsideTesting);
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
