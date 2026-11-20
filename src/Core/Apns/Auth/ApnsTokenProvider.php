<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns\Auth;

use Padosoft\MobilePush\Core\Auth\AccessTokenProvider;
use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Auth\JwtEncoder;
use Padosoft\MobilePush\Core\Auth\Signer;
use Padosoft\MobilePush\Core\Auth\TokenCacheKey;
use Padosoft\MobilePush\Core\Auth\TokenSlot;
use Padosoft\MobilePush\Core\Auth\TokenStore;
use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Psr\Clock\ClockInterface;

/**
 * APNs provider token (JWT ES256) with Apple's rules: never refresh more often than every 20 minutes,
 * never use a token older than 60 minutes. Order of lookup: in-process memo, shared store, mint.
 * Refresh-ahead is done by a single lock winner; everybody else keeps using the still-valid token.
 */
final class ApnsTokenProvider implements AccessTokenProvider
{
    public const MIN_MINT_INTERVAL_SECONDS = 1200;

    /** Hard limit is Apple's 3600 s; a safety margin absorbs clock skew and request latency. */
    public const DEFAULT_MAX_AGE_SECONDS = 3300;

    private readonly TokenSlot $slot;

    private readonly int $refreshAfterSeconds;

    private readonly string $cacheKey;

    public function __construct(
        private readonly Signer $signer,
        private readonly string $teamId,
        private readonly TokenStore $store,
        private readonly ClockInterface $clock,
        string $scope = 'default',
        string $environment = 'production',
        int $refreshAfterSeconds = 2700,
        int $jitterSeconds = 300,
        private readonly int $maxAgeSeconds = self::DEFAULT_MAX_AGE_SECONDS,
        private readonly int $lockSeconds = 30,
    ) {
        if ($teamId === '' || $signer->keyId() === null || $signer->keyId() === '') {
            throw new ConfigurationException('APNs authentication needs a team id and a key id.');
        }
        if ($signer->algorithm() !== 'ES256') {
            throw new ConfigurationException('APNs provider tokens must be signed with ES256.');
        }
        if ($maxAgeSeconds > 3600 || $maxAgeSeconds <= self::MIN_MINT_INTERVAL_SECONDS) {
            throw new ConfigurationException('maxAgeSeconds must be above 1200 and at most 3600.');
        }
        if ($refreshAfterSeconds < self::MIN_MINT_INTERVAL_SECONDS || $refreshAfterSeconds >= $maxAgeSeconds) {
            throw new ConfigurationException('refreshAfterSeconds must be at least 1200 and below the maximum token age.');
        }

        $this->slot = new TokenSlot;
        $jitter = $jitterSeconds > 0 ? random_int(0, $jitterSeconds) : 0;
        $this->refreshAfterSeconds = max(self::MIN_MINT_INTERVAL_SECONDS, $refreshAfterSeconds - $jitter);
        $this->cacheKey = TokenCacheKey::make('apns', $scope, $this->identity(), $environment);
    }

    public function identity(): string
    {
        return $this->teamId.':'.$this->signer->keyId();
    }

    public function cacheKey(): string
    {
        return $this->cacheKey;
    }

    public function token(): AuthToken
    {
        $now = $this->clock->now();

        $memo = $this->slot->get();
        if ($memo !== null && $memo->ageSeconds($now) < $this->refreshAfterSeconds) {
            return $memo;
        }

        $stored = $this->store->get($this->cacheKey);
        $current = $this->newest($stored, $memo);
        if ($current !== null && $current !== $memo) {
            $this->remember($current);
        }

        if ($current === null || $current->ageSeconds($now) >= $this->maxAgeSeconds) {
            return $this->mint($stored?->generation(), $current);
        }

        if ($current->ageSeconds($now) < $this->refreshAfterSeconds) {
            return $current;
        }

        // Refresh-ahead: one winner re-mints, everyone else keeps the still-valid token.
        if ($stored === null) {
            // No shared coordination available (store down or entry lost): re-mint locally; CAS settles duplicates.
            return $this->mintOrKeep(null, $current);
        }
        if (! $this->store->acquireRefreshLock($this->cacheKey, $this->lockSeconds)) {
            return $current;
        }

        try {
            $fresh = $this->store->get($this->cacheKey) ?? $stored;
            if ($fresh->ageSeconds($this->clock->now()) < $this->refreshAfterSeconds) {
                return $this->remember($fresh);
            }

            return $this->mintOrKeep($fresh->generation(), $fresh);
        } finally {
            $this->store->releaseRefreshLock($this->cacheKey);
        }
    }

    public function refreshAfterRejection(AuthToken $rejected): AuthToken
    {
        $stored = $this->store->get($this->cacheKey);
        $current = $this->newest($stored, $this->slot->get());

        if ($current !== null && $current->generation() !== $rejected->generation()) {
            return $this->remember($current);
        }

        if ($current !== null && $current->ageSeconds($this->clock->now()) < self::MIN_MINT_INTERVAL_SECONDS) {
            // Apple forbids minting more often than every 20 minutes: keep the one we have.
            return $this->remember($current);
        }

        return $this->mint($stored?->generation(), $current);
    }

    private function remember(AuthToken $token): AuthToken
    {
        $this->slot->set($token);

        return $token;
    }

    private function mintOrKeep(?string $expectedGeneration, AuthToken $current): AuthToken
    {
        try {
            return $this->mint($expectedGeneration, $current);
        } catch (AuthenticationException) {
            return $current;
        }
    }

    private function mint(?string $expectedGeneration, ?AuthToken $known): AuthToken
    {
        $now = $this->clock->now();

        if ($known !== null && $known->ageSeconds($now) < self::MIN_MINT_INTERVAL_SECONDS) {
            return $this->remember($known);
        }

        $jwt = (new JwtEncoder)->encode(
            ['typ' => 'JWT', 'kid' => $this->signer->keyId()],
            ['iss' => $this->teamId, 'iat' => $now->getTimestamp()],
            $this->signer,
        );

        $token = new AuthToken($jwt, $now, $now->modify(sprintf('+%d seconds', $this->maxAgeSeconds)));
        $winner = $this->store->compareAndSwap($this->cacheKey, $expectedGeneration, $token, $this->maxAgeSeconds);

        return $this->remember($winner);
    }

    private function newest(?AuthToken $a, ?AuthToken $b): ?AuthToken
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $b->issuedAt > $a->issuedAt ? $b : $a;
    }

    /** @return array<mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A token provider cannot be serialized.');
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['identity' => $this->identity(), 'cacheKey' => $this->cacheKey, 'token' => '[redacted]'];
    }
}
