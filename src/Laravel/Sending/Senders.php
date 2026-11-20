<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Sending;

use Padosoft\MobilePush\Core\Driver\Sender;
use Padosoft\MobilePush\Core\Guard\AuthBreaker;
use Padosoft\MobilePush\Core\Guard\DestinationQualityBreaker;
use Padosoft\MobilePush\Core\Guard\Guards;
use Padosoft\MobilePush\Core\Guard\GuardStore;
use Padosoft\MobilePush\Core\Guard\RateLimiter;
use Padosoft\MobilePush\Core\Guard\ThrottleGate;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Policy\SafeModeGuard;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Core\Transport\Transport;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * One Sender per provider. They share the transport, the guard store (so breakers, throttle gates and rate buckets
 * are shared between workers and between aliases of the same account) and the retry policy; they differ only in
 * the rate ceiling (`guards.rate.fcm` vs `guards.rate.apns`), which the core RateLimiter takes per instance.
 */
final class Senders
{
    /** @var array<string, Sender> */
    private array $senders = [];

    public function __construct(
        private readonly Transport $transport,
        private readonly GuardStore $store,
        private readonly SafeModeGuard $safeMode,
        private readonly ClockInterface $clock,
        private readonly SendingSettings $settings,
        private readonly ?Redactor $redactor = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function for(Provider $provider): Sender
    {
        return $this->senders[$provider->value] ??= new Sender(
            $this->transport,
            $this->guards($provider === Provider::Fcm ? $this->settings->fcmRatePerMinute : $this->settings->apnsRatePerMinute),
            $this->safeMode,
            $this->clock,
            $this->settings->retry,
            redactor: $this->redactor,
            logger: $this->logger,
        );
    }

    /** Guards over the shared store; `$ratePerMinute` null = no application ceiling. */
    public function guards(?int $ratePerMinute): Guards
    {
        $s = $this->settings;

        return new Guards(
            new RateLimiter($this->store, $this->clock, $ratePerMinute, $s->reservedShare, $s->burst),
            new ThrottleGate($this->store, $this->clock),
            new AuthBreaker($this->store, $this->clock, $s->authThreshold, $s->authCooldown),
            new DestinationQualityBreaker($this->store, $this->clock, $s->qualityMinSample, $s->qualityThreshold, $s->qualityCooldown),
            $this->clock,
        );
    }

    public function transport(): Transport
    {
        return $this->transport;
    }
}
