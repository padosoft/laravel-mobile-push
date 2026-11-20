<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

enum AlertKind: string
{
    case CircuitOpened = 'circuit_opened';
    case CredentialFailedOver = 'credential_failed_over';
    case CredentialExpiring = 'credential_expiring';
    case CredentialExpired = 'credential_expired';
    case ErrorRateHigh = 'error_rate_high';
    case LedgerBacklog = 'ledger_backlog';
    case LogSinkFailing = 'log_sink_failing';
}
