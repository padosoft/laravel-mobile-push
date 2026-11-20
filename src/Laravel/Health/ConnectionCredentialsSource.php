<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

/**
 * Where the health module gets the credentials of every configured connection, so it does not depend on the push
 * manager. The integration step binds an implementation that resolves each connection and runs the Core inspectors;
 * until then NullConnectionCredentialsSource reports "not verifiable".
 */
interface ConnectionCredentialsSource
{
    /** False when this source cannot enumerate connections (health reports credentials as not_verifiable). */
    public function available(): bool;

    /** @return list<ConnectionCredentials> */
    public function connections(): array;
}
