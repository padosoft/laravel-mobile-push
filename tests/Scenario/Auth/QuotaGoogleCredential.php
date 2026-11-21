<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Auth;

use Google\Auth\FetchAuthTokenInterface;
use Google\Auth\GetQuotaProjectInterface;

/** A google/auth credential that also names a quota project (any value, to probe non-strings). */
final class QuotaGoogleCredential implements FetchAuthTokenInterface, GetQuotaProjectInterface
{
    public function __construct(private readonly mixed $quotaProject, private readonly string $cacheKey = 'cred-a') {}

    public function fetchAuthToken(?callable $httpHandler = null)
    {
        return ['access_token' => 'ya29.quota', 'expires_in' => 3600];
    }

    public function getCacheKey()
    {
        return $this->cacheKey;
    }

    public function getLastReceivedToken()
    {
        return null;
    }

    public function getQuotaProject()
    {
        return $this->quotaProject;
    }
}
