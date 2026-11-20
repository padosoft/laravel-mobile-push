<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Auth;

use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;

/**
 * Produces secret text (PEM or service-account JSON) from a cacheable config array:
 * exactly one of `path`, `inline`, `base64` or `resolver` (+ `reference`). No closures.
 */
final class Secret
{
    /**
     * @param  array<string, mixed>  $source
     * @param  (\Closure(class-string<SecretResolver>): SecretResolver)|null  $resolverFactory  container hook; defaults to `new $class()`
     */
    public static function resolve(#[\SensitiveParameter] array $source, ?\Closure $resolverFactory = null): string
    {
        $present = array_values(array_filter(
            ['path', 'inline', 'base64', 'resolver'],
            static fn (string $k): bool => isset($source[$k]) && $source[$k] !== '',
        ));

        if (count($present) !== 1) {
            throw new AuthenticationException('A secret needs exactly one of: path, inline, base64, resolver.');
        }

        $kind = $present[0];
        $value = $source[$kind];
        if (! is_string($value)) {
            throw new AuthenticationException(sprintf('The secret source "%s" must be a string.', $kind));
        }

        $text = match ($kind) {
            'path' => self::fromPath($value),
            'inline' => $value,
            'base64' => self::fromBase64($value),
            default => self::fromResolver($value, $source['reference'] ?? null, $resolverFactory),
        };

        if (trim($text) === '') {
            throw new AuthenticationException(sprintf('The secret source "%s" is empty.', $kind));
        }

        return $text;
    }

    private static function fromPath(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new AuthenticationException('The secret file does not exist or is not readable.');
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new AuthenticationException('The secret file could not be read.');
        }

        return $content;
    }

    private static function fromBase64(string $value): string
    {
        $decoded = base64_decode(preg_replace('/\s+/', '', $value) ?? '', true);
        if ($decoded === false) {
            throw new AuthenticationException('The base64 secret is not valid base64.');
        }

        return $decoded;
    }

    /** @param (\Closure(class-string<SecretResolver>): SecretResolver)|null $factory */
    private static function fromResolver(string $class, mixed $reference, ?\Closure $factory): string
    {
        if (! is_string($reference) || $reference === '') {
            throw new AuthenticationException('A resolver secret needs a non-empty "reference".');
        }
        if (! class_exists($class) || ! is_subclass_of($class, SecretResolver::class)) {
            throw new AuthenticationException('The secret resolver class does not exist or does not implement SecretResolver.');
        }

        /** @var class-string<SecretResolver> $class */
        $resolver = $factory !== null ? $factory($class) : new $class;

        return $resolver->resolve($reference);
    }
}
