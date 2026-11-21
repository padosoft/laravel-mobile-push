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
     *                                                                        provider message id; returns the opaque marker to embed (or null)
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
        /**
         * Per-call observers: they receive the same callbacks as the Sender's own observers, after them, with the
         * same isolation (a throwing observer never changes a result).
         *
         * @var list<SendObserver>
         */
        public array $observers = [],
        /**
         * Id of the stored effect this call belongs to (a queued send: the id `queue()` returned). Carried to the
         * observers of the call so that results can be traced back to it; it never changes what is sent.
         */
        public ?string $effectId = null,
    ) {
        foreach ($observers as $observer) {
            // Runtime guard for callers that ignore the phpdoc.
            if (! $observer instanceof SendObserver) { // @phpstan-ignore instanceof.alwaysTrue
                throw new ConfigurationException('Every per-call observer must implement '.SendObserver::class.'.');
            }
        }
        if ($effectKey !== null && trim($effectKey) === '') {
            throw new ConfigurationException('The effect key cannot be empty: pass null to send without one.');
        }
        if ($window !== null && ($window < 1 || $window > self::MAX_WINDOW)) {
            throw new ConfigurationException('The send window must be between 1 and '.self::MAX_WINDOW.'.');
        }
        $this->effectiveMode = $mode ?? (new ModeResolver)->resolve($configuredMode, $applicationEnvironment ?? '');
        $this->receiptMarker = $receiptMarker === null ? null : \Closure::fromCallable($receiptMarker);
    }

    public function withObserver(SendObserver $observer): self
    {
        return $this->copy(['observers' => [...$this->observers, $observer]]);
    }

    public function withGate(DestinationGate $gate): self
    {
        return $this->copy(['gates' => [...$this->gates, $gate]]);
    }

    public function withEffectKey(?string $effectKey): self
    {
        return $this->copy(['effectKey' => $effectKey]);
    }

    public function withEffectId(?string $effectId): self
    {
        return $this->copy(['effectId' => $effectId]);
    }

    public function withExpiresAt(?\DateTimeImmutable $expiresAt): self
    {
        return $this->copy(['expiresAt' => $expiresAt]);
    }

    public function withTrafficClass(TrafficClass $trafficClass): self
    {
        return $this->copy(['trafficClass' => $trafficClass]);
    }

    public function withValidateOnly(bool $validateOnly): self
    {
        return $this->copy(['validateOnly' => $validateOnly]);
    }

    /** The same options with other live-sending settings (the non-production allow-list of the binding). */
    public function withLive(LiveSettings $live): self
    {
        return $this->copy(['live' => $live]);
    }

    public function withWindow(?int $window): self
    {
        return $this->copy(['window' => $window]);
    }

    public function withRetryPolicy(?RetryPolicy $retryPolicy): self
    {
        return $this->copy(['retryPolicy' => $retryPolicy]);
    }

    /**
     * @param  (callable(Destination, string): ?string)|null  $receiptMarker
     */
    public function withReceiptMarker(?callable $receiptMarker): self
    {
        return $this->copy(['receiptMarker' => $receiptMarker]);
    }

    /**
     * A new instance with the given fields replaced and every other one carried over, the resolved mode included.
     *
     * @param  array<string, mixed>  $changes
     */
    private function copy(array $changes): self
    {
        /** @var array{live: LiveSettings, trafficClass: TrafficClass, validateOnly: bool, effectKey: ?string, expiresAt: ?\DateTimeImmutable, window: ?int, retryPolicy: ?RetryPolicy, receiptMarker: (callable(Destination, string): ?string)|null, gates: list<DestinationGate>, observers: list<SendObserver>, effectId: ?string} $v */
        $v = array_replace([
            'live' => $this->live,
            'trafficClass' => $this->trafficClass,
            'validateOnly' => $this->validateOnly,
            'effectKey' => $this->effectKey,
            'retryPolicy' => $this->retryPolicy,
            'receiptMarker' => $this->receiptMarker,
            'gates' => $this->gates,
            'expiresAt' => $this->expiresAt,
            'window' => $this->window,
            'observers' => $this->observers,
            'effectId' => $this->effectId,
        ], $changes);

        return new self(
            $v['trafficClass'],
            $v['validateOnly'],
            $v['effectKey'],
            $this->applicationEnvironment,
            $this->configuredMode,
            $this->effectiveMode,
            $v['live'],
            $v['retryPolicy'],
            $v['receiptMarker'],
            $v['gates'],
            $v['expiresAt'],
            $v['window'],
            $v['observers'],
            $v['effectId'],
        );
    }
}
