<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

use Padosoft\MobilePush\Core\Identity\Platform;

/**
 * A SUGGESTION of the platform of a list of raw tokens, shown to a person in an interactive terminal who must confirm
 * it. It is never used to route a send on its own (the package never infers the platform from the shape of a token).
 *
 * Only an unambiguous list gets a proposal: every token looks like an APNs device token (even-length hexadecimal,
 * at least 64 characters), or none does. A mixed list gets none: platforms are never mixed in one invocation.
 */
final class PlatformProposal
{
    /** @param  list<string>  $tokens */
    public static function forTokens(#[\SensitiveParameter] array $tokens): ?Platform
    {
        if ($tokens === []) {
            return null;
        }
        $hex = 0;
        foreach ($tokens as $token) {
            if (preg_match('/^(?:[0-9a-fA-F]{2}){32,}$/', $token) === 1) {
                $hex++;
            }
        }

        return match (true) {
            $hex === count($tokens) => Platform::Ios,
            $hex === 0 => Platform::Android,
            default => null,
        };
    }
}
