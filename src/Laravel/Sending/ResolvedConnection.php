<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending;

use Padosoft\MobilePush\Core\Driver\Driver;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Policy\LiveSettings;

/**
 * One connection, built and validated: its binding, its driver, its live settings and the transport pools it uses
 * (closed when the connection is forgotten or evicted from the cache).
 */
final readonly class ResolvedConnection
{
    /**
     * @param  list<string>  $poolKeys
     * @param  list<TrackingTokenProvider>  $tokenProviders
     */
    public function __construct(
        public BindingRef $binding,
        public Driver $driver,
        public LiveSettings $live,
        public array $poolKeys,
        private array $tokenProviders,
    ) {}

    /**
     * The bearers handed out so far (runtime secrets the log sinks must redact).
     *
     * @return list<string>
     */
    public function bearers(): array
    {
        $out = [];
        foreach ($this->tokenProviders as $provider) {
            $bearer = $provider->lastBearer();
            if ($bearer !== null && $bearer !== '') {
                $out[] = $bearer;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['binding' => $this->binding->toArray(), 'pools' => count($this->poolKeys)];
    }
}
