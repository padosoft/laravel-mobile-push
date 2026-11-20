<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy;

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;

/**
 * Test-only replacement of provider origins (for a local mock server).
 *
 * EndpointCatalog accepts it only when `$testCredentials` is true AND the application environment is not
 * production. Origins are `https://host[:port]` with no path, query, fragment or user info.
 */
final readonly class EndpointOverride
{
    /** @var array<string, string> role value => origin */
    public array $origins;

    /**
     * @param  array<string, string>  $origins  EndpointRole value => origin
     * @param  bool  $testCredentials  must be true: the caller affirms it is using throw-away test credentials
     */
    public function __construct(array $origins, public bool $testCredentials = false)
    {
        $clean = [];

        foreach ($origins as $role => $origin) {
            if (EndpointRole::tryFrom($role) === null) {
                throw new ConfigurationException('Unknown endpoint role in override.');
            }

            $parts = parse_url($origin);

            if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
                throw new ConfigurationException('An endpoint override must be a bare https origin (https://host[:port]).');
            }

            $clean[$role] = 'https://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
        }

        $this->origins = $clean;
    }

    public function origin(EndpointRole $role): ?string
    {
        return $this->origins[$role->value] ?? null;
    }
}
