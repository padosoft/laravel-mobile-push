<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Transport\Curl;

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;

/**
 * Whether CurlTransport keeps connections between send() calls.
 *
 *  - On:   every send() uses the persistent pool of its pool key in the CurlPoolRegistry (connections reused across
 *          sends of the process; concurrent sends of one key share one curl_multi handle);
 *  - Off:  every send() opens its own private pool (its own curl_multi handle and connections) and closes it when
 *          that send's generator finishes or is destroyed. Nothing is kept or shared between sends;
 *  - Auto: On, except for a send that starts while a coroutine runtime hooks curl (see CoroutineCurlHook): that send
 *          runs as Off.
 */
enum ConnectionReuse: string
{
    case Auto = 'auto';
    case On = 'on';
    case Off = 'off';

    /**
     * Parses a configuration value: 'auto', 'on', 'off' (any letter case, surrounding blanks ignored); null means
     * "not set" (Auto); true/false (what Laravel's env() makes of "true"/"false") mean On/Off.
     *
     * @throws ConfigurationException for any other value, before anything is sent
     */
    public static function fromConfig(mixed $value, string $key = 'transport.reuse'): self
    {
        if ($value === null) {
            return self::Auto;
        }
        if (is_bool($value)) {
            return $value ? self::On : self::Off;
        }
        if (is_string($value)) {
            $reuse = self::tryFrom(strtolower(trim($value)));
            if ($reuse !== null) {
                return $reuse;
            }
        }

        $shown = is_scalar($value) ? var_export($value, true) : get_debug_type($value);

        throw new ConfigurationException("Invalid mobile-push.{$key} {$shown}: allowed values are 'auto', 'on', 'off'.");
    }
}
