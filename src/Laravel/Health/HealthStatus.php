<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    /** Something that stops or will stop pushes from working. */
    case Failed = 'failed';
    /** The check cannot be decided from here (no source, optional dependency missing). It never degrades the overall status. */
    case NotVerifiable = 'not_verifiable';

    /** Severity used to combine statuses: not_verifiable counts like ok. */
    public function rank(): int
    {
        return match ($this) {
            self::Ok, self::NotVerifiable => 0,
            self::Warning => 1,
            self::Failed => 2,
        };
    }

    /** Process exit code of `mobile-push:health`: 0 ok (also not_verifiable), 1 warning, 2 failed. */
    public function exitCode(): int
    {
        return $this->rank();
    }
}
