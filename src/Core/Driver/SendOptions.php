<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Driver;

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Policy\ExecutionMode;
use Padosoft\MobilePush\Core\Policy\LiveSettings;
use Padosoft\MobilePush\Core\Policy\ModeResolver;
use Padosoft\MobilePush\Core\Retry\RetryPolicy;

/**
 * Per-call options of Sender::send(). Validated at construction (before the network).
 *
 * Execution mode: `$mode` when given (already resolved by the caller), otherwise ModeResolver over
 * `$configuredMode` and `$applicationEnvironment`. The application environment defaults to null, which is NOT
 * production: with the default `auto` mode, a caller that forgets to say where it runs simulates instead of
 * sending.
 */
final readonly class SendOptions
{
    public const MAX_WINDOW = 10_000;

    public ExecutionMode $effectiveMode;

    /** @var (\Closure(Destination, string): ?string)|null */
    public ?\Closure $receiptMarker;

    /**
     * @param  (callable(Destination, string): ?string)|null  $receiptMarker  called once per live destination with the
     *                                                                         provider message id; returns the opaque marker to embed (or null)
     * @param  list<DestinationGate>  $gates  evaluated in order, see DestinationGate
     */
    public function __construct(
        public TrafficClass $trafficClass = TrafficClass::Transactional,
        public bool $validateOnly = false,
        /** Logical effect key: makes provider message ids deterministic (UUIDv5) across retries and resumes. */
        public ?string $effectKey = null,
        public ?string $applicationEnvironment = null,
        public string $configuredMode = 'auto',
        public ?ExecutionMode $mode = null,
        public LiveSettings $live = new LiveSettings,
        public ?RetryPolicy $retryPolicy = null,
        ?callable $receiptMarker = null,
        public array $gates = [],
        /** Absolute logical expiry pinned by the caller (e.g. the effect row); overrides the message's own. */
        public ?\DateTimeImmutable $expiresAt = null,
        /** Requests per window; null = the driver's window. */
        public ?int $window = null,
    ) {
        if ($effectKey !== null && trim($effectKey) === '') {
            throw new ConfigurationException('The effect key cannot be empty: pass null to send without one.');
        }
        if ($window !== null && ($window < 1 || $window > self::MAX_WINDOW)) {
            throw new ConfigurationException('The send window must be between 1 and '.self::MAX_WINDOW.'.');
        }
        $this->effectiveMode = $mode ?? (new ModeResolver)->resolve($configuredMode, $applicationEnvironment ?? '');
        $this->receiptMarker = $receiptMarker === null ? null : \Closure::fromCallable($receiptMarker);
    }
}
