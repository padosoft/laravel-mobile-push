<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Padosoft\MobilePush\Laravel\Sending\PushManager;

/**
 * Facade of the push manager.
 *
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend connection(?string $name = null)
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend scope(string $scope)
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend to(mixed $target)
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend toToken(\Padosoft\MobilePush\Core\Identity\Platform $platform, string $token)
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend toTokens(\Padosoft\MobilePush\Core\Identity\Platform $platform, iterable<string> $tokens)
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend toTopic(string $topic)
 * @method static \Padosoft\MobilePush\Laravel\Sending\PendingSend toCondition(string $condition)
 * @method static void register(string $name, array<mixed> $config, ?string $scope = null)
 * @method static void resolveConnectionUsing(callable $resolver)
 * @method static void scopeUsing(callable $resolver)
 * @method static void eligibleUsing(callable $check, bool $batch = false)
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
    protected static function getFacadeAccessor(): string
    {
        return PushManager::class;
    }
}
