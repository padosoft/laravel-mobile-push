<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Auth;

use Padosoft\MobilePush\Core\Auth\AccessTokenProvider;
use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Auth\JwtEncoder;
use Padosoft\MobilePush\Core\Auth\OpenSslRs256Signer;
use Padosoft\MobilePush\Core\Auth\Signer;
use Padosoft\MobilePush\Core\Auth\TokenCacheKey;
use Padosoft\MobilePush\Core\Auth\TokenSlot;
use Padosoft\MobilePush\Core\Auth\TokenStore;
use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\HttpVersion;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Transport;
use Psr\Clock\ClockInterface;

/**
 * Google service-account flow: sign an RS256 assertion, EXCHANGE it at the official token endpoint for an
 * OAuth access token, cache that token until `expires_in` minus a skew. The assertion is never the bearer.
 */
final class ServiceAccountTokenProvider implements AccessTokenProvider
{
    public const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    public const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    private readonly TokenSlot $slot;

    private readonly Signer $signer;

    private readonly string $endpoint;

    private readonly string $cacheKey;

    /**
     * @param  string|null  $tokenEndpointOverride  test hook only; the file's own token_uri is never trusted
     */
    public function __construct(
        private readonly ServiceAccountCredentials $credentials,
        private readonly Transport $transport,
        private readonly TokenStore $store,
        private readonly ClockInterface $clock,
        string $scope = 'default',
        private readonly string $oauthScope = self::FCM_SCOPE,
        private readonly int $skewSeconds = 300,
        private readonly int $lockSeconds = 30,
        ?string $tokenEndpointOverride = null,
        ?Signer $signer = null,
        private readonly ?ConnectionProfile $profile = null,
    ) {
        $this->slot = new TokenSlot;
        $this->signer = $signer ?? new OpenSslRs256Signer($credentials->privateKey(), $credentials->privateKeyId);
        $this->endpoint = $tokenEndpointOverride ?? self::TOKEN_ENDPOINT;
        $this->cacheKey = TokenCacheKey::make('fcm', $scope, $credentials->identity(), $oauthScope);
    }

    public function identity(): string
    {
        return $this->credentials->identity();
    }

    public function cacheKey(): string
    {
        return $this->cacheKey;
    }

    public function token(): AuthToken
    {
        $memo = $this->slot->get();
        if ($memo !== null && $this->isFresh($memo)) {
            return $memo;
        }

        $stored = $this->store->get($this->cacheKey);
        if ($stored !== null && $this->isFresh($stored)) {
            return $this->remember($stored);
        }

        $stale = $stored ?? $memo;
        $locked = $this->store->acquireRefreshLock($this->cacheKey, $this->lockSeconds);
        if (! $locked && $stale !== null && $this->isUsable($stale)) {
            // Someone else is refreshing: keep using the token that has not expired yet.
            return $this->remember($stale);
        }

        try {
            if ($locked) {
                $again = $this->store->get($this->cacheKey);
                if ($again !== null && $this->isFresh($again)) {
                    return $this->remember($again);
                }
            }

            return $this->exchange($stored?->generation());
        } finally {
            if ($locked) {
                $this->store->releaseRefreshLock($this->cacheKey);
            }
        }
    }

    public function refreshAfterRejection(AuthToken $rejected): AuthToken
    {
        $stored = $this->store->get($this->cacheKey);
        $current = $stored ?? $this->slot->get();

        if ($current !== null && $current->generation() !== $rejected->generation() && $this->isUsable($current)) {
            return $this->remember($current);
        }

        return $this->exchange($stored?->generation());
    }

    private function remember(AuthToken $token): AuthToken
    {
        $this->slot->set($token);

        return $token;
    }

    private function isFresh(AuthToken $token): bool
    {
        return $token->expiresAt === null
            || $this->clock->now()->getTimestamp() < $token->expiresAt->getTimestamp() - $this->skewSeconds;
    }

    private function isUsable(AuthToken $token): bool
    {
        return $token->expiresAt === null || $this->clock->now()->getTimestamp() < $token->expiresAt->getTimestamp();
    }

    private function exchange(?string $expectedGeneration): AuthToken
    {
        $now = $this->clock->now();
        $iat = $now->getTimestamp();

        $assertion = (new JwtEncoder)->encode(
            ['typ' => 'JWT'],
            [
                'iss' => $this->credentials->clientEmail,
                'scope' => $this->oauthScope,
                'aud' => $this->endpoint,
                'iat' => $iat,
                'exp' => $iat + 3600,
            ],
            $this->signer,
        );

        $request = new Request(
            id: 'oauth-token',
            method: 'POST',
            url: $this->endpoint,
            headers: [
                'content-type' => 'application/x-www-form-urlencoded',
                'accept' => 'application/json',
            ],
            body: http_build_query(['grant_type' => self::GRANT_TYPE, 'assertion' => $assertion], '', '&', PHP_QUERY_RFC3986),
        );
        unset($assertion);

        $profile = $this->profile ?? new ConnectionProfile(
            poolKey: 'google-oauth:'.$this->cacheKey,
            httpVersion: HttpVersion::Http11,
            requireHttp2: false,
            maxConnections: 1,
        );

        $exchange = null;
        try {
            foreach ($this->transport->send([$request], $profile) as $item) {
                $exchange = $item;
                break;
            }
        } catch (\Throwable) {
            throw new AuthenticationException('The Google token exchange could not be performed (transport error).');
        }

        if ($exchange === null) {
            throw new AuthenticationException('The Google token exchange returned no result.');
        }
        if ($exchange->response === null) {
            $errno = $exchange->failure->errno ?? 0;
            throw new AuthenticationException(sprintf('The Google token exchange failed at transport level (errno %d).', $errno));
        }

        $response = $exchange->response;
        $json = $response->json();
        if ($response->status < 200 || $response->status > 299) {
            $code = is_array($json) && isset($json['error']) && is_string($json['error']) && preg_match('/^[a-z_]{1,64}$/', $json['error']) === 1
                ? ' ('.$json['error'].')'
                : '';
            throw new AuthenticationException(sprintf('The Google token exchange was refused with HTTP %d%s.', $response->status, $code));
        }

        $accessToken = is_array($json) ? ($json['access_token'] ?? null) : null;
        $expiresIn = is_array($json) ? ($json['expires_in'] ?? null) : null;
        if (! is_string($accessToken) || $accessToken === '' || ! is_numeric($expiresIn) || (int) $expiresIn <= 0) {
            throw new AuthenticationException('The Google token exchange returned a malformed response.');
        }

        $lifetime = (int) $expiresIn;
        $token = new AuthToken($accessToken, $now, $now->modify(sprintf('+%d seconds', $lifetime)));
        $winner = $this->store->compareAndSwap($this->cacheKey, $expectedGeneration, $token, $lifetime);

        return $this->remember($winner);
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
