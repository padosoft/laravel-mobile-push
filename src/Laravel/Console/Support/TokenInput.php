<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

/** Collects raw tokens from arguments, a file (one per line) and standard input. Blank lines and `#` comments are skipped. */
final class TokenInput
{
    /**
     * @param  list<string>  $arguments
     * @return list<string> unique tokens in first-seen order
     *
     * @throws ConsoleInputException
     */
    public static function collect(#[\SensitiveParameter] array $arguments, ?string $file, ?string $stdin): array
    {
        $tokens = $arguments;

        if ($file !== null) {
            if (! is_file($file) || ! is_readable($file)) {
                throw new ConsoleInputException(sprintf('The token file "%s" does not exist or is not readable. Pass a readable file with one token per line.', $file));
            }
            $tokens = [...$tokens, ...self::lines((string) file_get_contents($file))];
        }
        if ($stdin !== null) {
            $tokens = [...$tokens, ...self::lines($stdin)];
        }

        $unique = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token !== '') {
                $unique[$token] = true;
            }
        }

        return array_map('strval', array_keys($unique));
    }

    /** @return list<string> */
    private static function lines(#[\SensitiveParameter] string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && ! str_starts_with($line, '#')) {
                $out[] = $line;
            }
        }

        return $out;
    }
}
