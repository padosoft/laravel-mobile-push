<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Auth;

use Google\Auth\ApplicationDefaultCredentials;
use Google\Auth\Credentials\ImpersonatedServiceAccountCredentials;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\FetchAuthTokenInterface;
use Google\Auth\GetQuotaProjectInterface;
use Padosoft\MobilePush\Core\Auth\AccessTokenProvider;
use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Auth\TokenCacheKey;
use Padosoft\MobilePush\Core\Auth\TokenSlot;
use Padosoft\MobilePush\Core\Auth\TokenStore;
use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Psr\Clock\ClockInterface;

/**
 * Adapter over the optional `google/auth` package for what the native service-account provider does not
 * cover: Application Default Credentials, the metadata server (GCE / Cloud Run / GKE), Workload Identity
 * Federation and impersonation. google/auth makes its own HTTP calls (Guzzle): accepted for token
 * acquisition only; FCM sends never go through it.
 *
 * Coordination is the same as the other providers: in-process memo, then the shared TokenStore, then a
 * fetch by a single refresher; refresh happens `$skewSeconds` before expiry.
 */
final class GoogleAuthLibraryProvider implements AccessTokenProvider
{
    public const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Lifetime assumed when the library returns neither expires_in nor expires_at (Google access tokens last 1 hour). */
    private const DEFAULT_LIFETIME = 3600;

    private readonly TokenSlot $slot;

    private readonly string $identity;

    private readonly string $cacheKey;

    /** @var \SplObjectStorage<\stdClass, FetchAuthTokenInterface> holds the credential where var_export()/print_r() cannot reach its key material */
    private readonly \SplObjectStorage $vault;

    private readonly \stdClass $vaultKey;

    public function __construct(
        FetchAuthTokenInterface $credential,
        private readonly TokenStore $store,
        private readonly ClockInterface $clock,
        string $scope = 'default',
        string $oauthScope = self::FCM_SCOPE,
        private readonly int $skewSeconds = 300,
        private readonly int $lockSeconds = 30,
    ) {
        $this->slot = new TokenSlot;
        $this->vault = new \SplObjectStorage;
        $this->vaultKey = new \stdClass;
        $this->vault[$this->vaultKey] = $credential;
        $this->identity = self::deriveIdentity($credential);
        $this->cacheKey = TokenCacheKey::make('fcm', $scope, $this->identity, $oauthScope);
    }

    /**
     * Application Default Credentials with the FCM scope.
     *
     * @throws ConfigurationException when google/auth is not installed or no default credentials exist
     */
    public static function applicationDefault(TokenStore $store, ClockInterface $clock, string $scope = 'default', int $skewSeconds = 300): self
    {
        if (! class_exists(ApplicationDefaultCredentials::class)) {
            throw new ConfigurationException('Application Default Credentials need the google/auth package. Run: composer require google/auth');
        }

        try {
            $credential = ApplicationDefaultCredentials::getCredentials(self::FCM_SCOPE);
        } catch (\Throwable) {
            throw new ConfigurationException('No Application Default Credentials were found. Set GOOGLE_APPLICATION_CREDENTIALS, run on Google Cloud with an attached service account, or pass a credential built by google/auth.');
        }

        return new self($credential, $store, $clock, $scope, self::FCM_SCOPE, $skewSeconds);
    }

    /**
     * Non-secret and stable. A service account (or an impersonated one) exposes its email without any network
     * call: `google-auth:<class>:<email>`. Every other credential (metadata server, user refresh, external
     * account...) has no principal that can be read offline, so the identity is
     * `google-auth:<class>:<first 16 hex of sha256(class | quota project | library cache key)>`.
     */
    public function identity(): string
    {
        return $this->identity;
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
            return $this->remember($stale);
        }

        try {
            if ($locked) {
                $again = $this->store->get($this->cacheKey);
                if ($again !== null && $this->isFresh($again)) {
                    return $this->remember($again);
                }
            }

            return $this->fetch($stored?->generation());
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

        return $this->fetch($stored?->generation());
    }

    private function fetch(?string $expectedGeneration): AuthToken
    {
        $now = $this->clock->now();

        try {
            /** @var mixed $result */
            $result = $this->vault[$this->vaultKey]->fetchAuthToken();
        } catch (\Throwable $e) {
            // Only the class name: library messages may echo response bodies.
            throw new AuthenticationException(sprintf('The google/auth credential could not obtain an access token (%s).', $e::class));
        }

        $accessToken = is_array($result) ? ($result['access_token'] ?? null) : null;
        if (! is_string($accessToken) || $accessToken === '') {
            throw new AuthenticationException('The google/auth credential returned no access token.');
        }

        $lifetime = self::DEFAULT_LIFETIME;
        if (isset($result['expires_in']) && is_numeric($result['expires_in']) && (int) $result['expires_in'] > 0) {
            $lifetime = (int) $result['expires_in'];
        } elseif (isset($result['expires_at']) && is_numeric($result['expires_at']) && (int) $result['expires_at'] > $now->getTimestamp()) {
            $lifetime = (int) $result['expires_at'] - $now->getTimestamp();
        }

        $token = new AuthToken($accessToken, $now, $now->modify(sprintf('+%d seconds', $lifetime)));
        unset($result, $accessToken);

        return $this->remember($this->store->compareAndSwap($this->cacheKey, $expectedGeneration, $token, $lifetime));
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

    private static function deriveIdentity(FetchAuthTokenInterface $credential): string
    {
        $class = $credential::class;
        $short = substr(strrchr('\\'.$class, '\\') ?: $class, 1);

        $principal = null;
        if ($credential instanceof ServiceAccountCredentials || $credential instanceof ImpersonatedServiceAccountCredentials) {
            try {
                /** @var mixed $name */
                $name = $credential->getClientName();
                $principal = is_string($name) && $name !== '' ? $name : null;
            } catch (\Throwable) {
                $principal = null;
            }
        }
        if ($principal !== null) {
            return 'google-auth:'.$short.':'.$principal;
        }

        $quota = '';
        if ($credential instanceof GetQuotaProjectInterface) {
            /** @var mixed $quotaProject */
            $quotaProject = $credential->getQuotaProject();
            $quota = is_string($quotaProject) ? $quotaProject : '';
        }
        $libraryKey = '';
        try {
            /** @var mixed $key */
            $key = $credential->getCacheKey();
            $libraryKey = is_string($key) ? $key : '';
        } catch (\Throwable) {
            // identity falls back to class + quota project
        }

        return 'google-auth:'.$short.':'.substr(hash('sha256', $class."\0".$quota."\0".$libraryKey), 0, 16);
    }

    /** @return array<mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A token provider cannot be serialized.');
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['identity' => $this->identity, 'cacheKey' => $this->cacheKey, 'token' => '[redacted]'];
    }
}
