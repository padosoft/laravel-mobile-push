<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Auth;

use Google\Auth\FetchAuthTokenInterface;

/**
 * A google/auth credential that answers fetchAuthToken() with a scripted array and counts the calls.
 * `getClientName()` exists (as on the metadata-server credential, where calling it is a network request)
 * and is counted, so a test can prove the provider never asks a non-service-account credential for it.
 */
final class ScriptedGoogleCredential implements FetchAuthTokenInterface
{
    public int $fetches = 0;

    public int $clientNameCalls = 0;

    /**
     * @param  array<string, mixed>|\Closure(int): mixed  $result  the array to return, or a closure given the fetch number
     */
    public function __construct(
        private readonly array|\Closure $result = ['access_token' => 'ya29.scripted', 'expires_in' => 3600],
        private readonly mixed $cacheKey = 'cred-a',
        private readonly bool $cacheKeyThrows = false,
    ) {}

    public function fetchAuthToken(?callable $httpHandler = null)
    {
        $this->fetches++;

        return $this->result instanceof \Closure ? ($this->result)($this->fetches) : $this->result;
    }

    public function getCacheKey()
    {
        if ($this->cacheKeyThrows) {
            throw new \RuntimeException('no cache key offline');
        }

        return $this->cacheKey;
    }

    public function getLastReceivedToken()
    {
        return null;
    }

    public function getClientName(?callable $httpHandler = null): string
    {
        $this->clientNameCalls++;

        return 'someone@example.test';
    }
}
