<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

enum DoctorStatus: string
{
    case Ok = 'ok';
    case Info = 'info';
    case Warning = 'warning';
    /** Cannot be checked from here: never reported as ok. */
    case NotVerifiable = 'not_verifiable';
    case Failed = 'failed';

    public function symbol(): string
    {
        return match ($this) {
            self::Ok => '✓',
            self::Info => 'i',
            self::Warning, self::NotVerifiable => '!',
            self::Failed => '✗',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'ok',
            self::Info => 'info',
            self::Warning => 'warning',
            self::NotVerifiable => 'not verifiable',
            self::Failed => 'failed',
        };
    }
}
