<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Auth;

use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;

/**
 * Supplies the bearer token for one credential identity, with coordinated refresh.
 */
interface AccessTokenProvider
{
    /**
     * A currently valid token. Concurrent callers reuse the same token; a refresh happens ahead of
     * expiry by a single winner while the others keep using the still-valid one.
     *
     * @throws AuthenticationException
     */
    public function token(): AuthToken;

    /**
     * The provider rejected `$rejected` as expired. Replace it only if it is still the current one
     * (compare-and-swap); otherwise adopt the newer token someone else already produced.
     *
     * @throws AuthenticationException
     */
    public function refreshAfterRejection(AuthToken $rejected): AuthToken;

    /** Non-secret identity of the credential (team+key id, certificate fingerprint, Google principal). */
    public function identity(): string;
}
