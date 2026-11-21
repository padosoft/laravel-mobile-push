<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Transport\Curl\ConnectionReuse;
use Padosoft\MobilePush\Core\Transport\Curl\CoroutineCurlHook;
use Padosoft\MobilePush\Core\Transport\Curl\CurlPoolRegistry;
use Padosoft\MobilePush\Core\Transport\Curl\CurlTransport;

/*
 * The detection behind `transport.reuse = auto`: reuse is switched off for a send only when a coroutine runtime has
 * hooked curl in this process. Swoole is not installed here: the facts are injected.
 */

/**
 * @param  list<string>  $extensions  loaded extensions
 * @param  array<string, int>  $flags  runtime class => getHookFlags()
 * @param  array<string, int>  $constants  defined constants
 */
function hookFacts(array $extensions = [], array $flags = [], array $constants = []): CoroutineCurlHook
{
    return new CoroutineCurlHook(
        static fn (string $extension): bool => in_array($extension, $extensions, true),
        static fn (string $class): ?int => $flags[$class] ?? null,
        static fn (string $name): ?int => $constants[$name] ?? null,
    );
}

// Swoole 6: SWOOLE_HOOK_ALL includes SWOOLE_HOOK_NATIVE_CURL (4096) but not SWOOLE_HOOK_CURL (2048).
const SWOOLE6_HOOK_ALL = 0x7FFFFFFF & ~2048;

dataset('facts', [
    'no coroutine extension' => [hookFacts(), false],
    'Swoole loaded, no hooks' => [hookFacts(['swoole'], ['Swoole\Runtime' => 0]), false],
    'Swoole, SWOOLE_HOOK_ALL (native curl included)' => [hookFacts(['swoole'], ['Swoole\Runtime' => SWOOLE6_HOOK_ALL]), true],
    'Swoole, only SWOOLE_HOOK_CURL (by value)' => [hookFacts(['swoole'], ['Swoole\Runtime' => 2048]), true],
    'Swoole, only SWOOLE_HOOK_NATIVE_CURL (by value)' => [hookFacts(['swoole'], ['Swoole\Runtime' => 4096]), true],
    'Swoole, ALL minus both curl hooks' => [hookFacts(['swoole'], ['Swoole\Runtime' => SWOOLE6_HOOK_ALL & ~(2048 | 4096)]), false],
    'Swoole, curl hook at the value its constant defines' => [hookFacts(['swoole'], ['Swoole\Runtime' => 1 << 20], ['SWOOLE_HOOK_NATIVE_CURL' => 1 << 20]), true],
    'Swoole loaded, flags unreadable (assumed hooked)' => [hookFacts(['swoole']), true],
    'Swoole class present but extension not loaded' => [hookFacts([], ['Swoole\Runtime' => 4096]), false],
    'OpenSwoole, HOOK_CURL through OpenSwoole\Runtime' => [hookFacts(['openswoole'], ['OpenSwoole\Runtime' => 2048]), true],
    'OpenSwoole, its own constant value' => [hookFacts(['openswoole'], ['OpenSwoole\Runtime' => 1 << 22], ['OpenSwoole\Runtime::HOOK_CURL' => 1 << 22]), true],
    'OpenSwoole, flags only through the Swoole\Runtime alias' => [hookFacts(['openswoole'], ['Swoole\Runtime' => 4096]), true],
    'OpenSwoole, hooks without curl' => [hookFacts(['openswoole'], ['OpenSwoole\Runtime' => 1 | 2 | 4]), false],
]);

it('detects a coroutine curl hook only from the runtime facts', function (CoroutineCurlHook $hook, bool $active): void {
    expect($hook->active())->toBe($active)
        ->and($hook->reason() !== null)->toBe($active);
})->with('facts');

it('finds no curl hook in this process (no Swoole here)', function (): void {
    expect(extension_loaded('swoole') || extension_loaded('openswoole'))->toBeFalse()
        ->and(CoroutineCurlHook::detect()->active())->toBeFalse();
});

it('decides reuse per send from the setting and the facts', function (ConnectionReuse $reuse, bool $hooked, bool $reuses): void {
    $hook = $hooked ? hookFacts(['swoole'], ['Swoole\Runtime' => 4096]) : hookFacts();

    expect((new CurlTransport(new CurlPoolRegistry, reuse: $reuse, hook: $hook))->reusesConnections())->toBe($reuses);
})->with([
    'on, no hook' => [ConnectionReuse::On, false, true],
    'on, curl hook (explicit: kept on)' => [ConnectionReuse::On, true, true],
    'off, no hook' => [ConnectionReuse::Off, false, false],
    'off, curl hook' => [ConnectionReuse::Off, true, false],
    'auto, no hook' => [ConnectionReuse::Auto, false, true],
    'auto, curl hook' => [ConnectionReuse::Auto, true, false],
]);

it('re-reads the facts when each send starts', function (): void {
    $hooked = false;
    $hook = new CoroutineCurlHook(
        static fn (string $extension): bool => $extension === 'swoole',
        static function (string $class) use (&$hooked): ?int {
            return $hooked ? 4096 : 0;
        },
        static fn (string $name): ?int => null,
    );
    $transport = new CurlTransport(new CurlPoolRegistry, hook: $hook);

    $before = $transport->reusesConnections();
    $hooked = true; // e.g. Swoole\Runtime::enableCoroutine() called later in the process

    expect($transport->reuse())->toBe(ConnectionReuse::Auto)
        ->and($before)->toBeTrue()
        ->and($transport->reusesConnections())->toBeFalse();
});

it('parses the transport.reuse setting', function (mixed $value, ConnectionReuse $expected): void {
    expect(ConnectionReuse::fromConfig($value))->toBe($expected);
})->with([
    ['auto', ConnectionReuse::Auto],
    ['on', ConnectionReuse::On],
    ['off', ConnectionReuse::Off],
    [' OFF ', ConnectionReuse::Off],
    [null, ConnectionReuse::Auto],
    [true, ConnectionReuse::On],
    [false, ConnectionReuse::Off],
]);

it('refuses any other transport.reuse value, naming the key and the allowed values', function (mixed $value): void {
    expect(fn () => ConnectionReuse::fromConfig($value))
        ->toThrow(ConfigurationException::class, 'mobile-push.transport.reuse');
    try {
        ConnectionReuse::fromConfig($value);
    } catch (ConfigurationException $e) {
        expect($e->getMessage())->toContain("'auto', 'on', 'off'");
    }
})->with(['yes', '', 1, 0, [['on']]]);
