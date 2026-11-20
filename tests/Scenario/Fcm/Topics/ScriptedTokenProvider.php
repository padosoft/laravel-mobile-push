<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Fcm\Topics;

use Padosoft\MobilePush\Core\Auth\AccessTokenProvider;
use Padosoft\MobilePush\Core\Auth\AuthToken;

/** Test stub: hands out "ya29.fake-access-1", then "ya29.fake-access-2", ... on every refresh and counts calls. */
final class ScriptedTokenProvider implements AccessTokenProvider
{
    public int $refreshes = 0;

    private int $generation = 1;

    public function token(): AuthToken
    {
        return new AuthToken('ya29.fake-access-'.$this->generation, new \DateTimeImmutable('2026-11-20T10:00:00Z'));
    }

    public function refreshAfterRejection(AuthToken $rejected): AuthToken
    {
        $this->refreshes++;
        $this->generation++;

        return $this->token();
    }

    public function identity(): string
    {
        return 'test-identity';
    }
}
