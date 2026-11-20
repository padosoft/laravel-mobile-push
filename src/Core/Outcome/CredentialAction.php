<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Outcome;

enum CredentialAction: string
{
    case None = 'none';
    /** Coordinated refresh of the provider token for the generation that failed, then one more attempt. */
    case RefreshToken = 'refresh_token';
    /** Slow token renewals down; never fail over to the secondary credential for this. */
    case SlowTokenRefresh = 'slow_token_refresh';
    /** The credential itself is wrong: feed the authentication breaker (failover is decided there, once). */
    case ReportAuthFailure = 'report_auth_failure';
}
