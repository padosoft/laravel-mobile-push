<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Transport\Curl;

/**
 * Detects a coroutine runtime that has hooked curl in this process (Swoole or OpenSwoole with SWOOLE_HOOK_CURL or
 * SWOOLE_HOOK_NATIVE_CURL on). Under such a hook a coroutine can be switched out INSIDE curl_multi_exec(), so a second
 * coroutine may drive the same curl_multi handle re-entrantly (measured under Octane on Swoole 6.2: fatal error in
 * curl_multi_exec, the worker dies). ConnectionReuse::Auto turns reuse off for a send that starts while this is active.
 *
 * Neither extension is a dependency and nothing is imported: the facts are read through injected closures (so the
 * detection is unit-tested without Swoole), and detect() reads them with extension_loaded(), class_exists(),
 * defined()/constant() and a dynamic call to <Runtime>::getHookFlags().
 *
 * When an extension is loaded but its hook flags cannot be read, the hook is assumed active (reuse off costs
 * handshakes; reuse on under a curl hook costs crashed workers).
 */
final readonly class CoroutineCurlHook
{
    /** Swoole's SWOOLE_HOOK_CURL (1 << 11), used when the constant is not defined. */
    public const HOOK_CURL = 2048;

    /** Swoole's SWOOLE_HOOK_NATIVE_CURL (1 << 12; part of SWOOLE_HOOK_ALL in Swoole 6), used when not defined. */
    public const HOOK_NATIVE_CURL = 4096;

    /**
     * extension => [runtime classes whose getHookFlags() is read (first available wins), curl hook constants].
     *
     * @var array<string, array{0: list<string>, 1: list<string>}>
     */
    private const RUNTIMES = [
        'swoole' => [
            ['Swoole\Runtime'],
            ['SWOOLE_HOOK_CURL', 'SWOOLE_HOOK_NATIVE_CURL'],
        ],
        'openswoole' => [
            ['OpenSwoole\Runtime', 'Swoole\Runtime'],
            ['OpenSwoole\Runtime::HOOK_CURL', 'OpenSwoole\Runtime::HOOK_NATIVE_CURL', 'SWOOLE_HOOK_CURL', 'SWOOLE_HOOK_NATIVE_CURL'],
        ],
    ];

    /**
     * @param  \Closure(string): bool  $extensionLoaded  extension name => loaded?
     * @param  \Closure(string): (int|null)  $hookFlags  runtime class => its current hook flags, null when the class or
     *                                                   its getHookFlags() method does not exist
     * @param  \Closure(string): (int|null)  $constant  constant name (global or Class::NAME) => its int value, null when undefined
     */
    public function __construct(
        private \Closure $extensionLoaded,
        private \Closure $hookFlags,
        private \Closure $constant,
    ) {}

    /** The facts of this process, read at each call of active()/reason(). */
    public static function detect(): self
    {
        return new self(
            static fn (string $extension): bool => extension_loaded($extension),
            static function (string $class): ?int {
                $method = [$class, 'getHookFlags'];
                if (! class_exists($class, false) || ! is_callable($method)) {
                    return null;
                }
                $flags = call_user_func($method);

                return is_int($flags) ? $flags : null;
            },
            static function (string $name): ?int {
                if (! defined($name)) {
                    return null;
                }
                $value = constant($name);

                return is_int($value) ? $value : null;
            },
        );
    }

    public function active(): bool
    {
        return $this->reason() !== null;
    }

    /** Why a curl hook is considered active (for logs and diagnostics), or null when none is. */
    public function reason(): ?string
    {
        foreach (self::RUNTIMES as $extension => [$classes, $constants]) {
            if (! ($this->extensionLoaded)($extension)) {
                continue;
            }

            $flags = null;
            $source = null;
            foreach ($classes as $class) {
                $flags = ($this->hookFlags)($class);
                if ($flags !== null) {
                    $source = $class;
                    break;
                }
            }
            if ($flags === null) {
                return "the {$extension} extension is loaded and its coroutine hook flags cannot be read";
            }

            $mask = self::HOOK_CURL | self::HOOK_NATIVE_CURL;
            foreach ($constants as $name) {
                $mask |= ($this->constant)($name) ?? 0;
            }
            if (($flags & $mask) !== 0) {
                return "{$source}::getHookFlags() = {$flags} includes a curl hook";
            }
        }

        return null;
    }
}
