<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Credentials;

enum ApnsCertificateKind: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';
    /** Valid for both environments (Apple extension 1.2.840.113635.100.6.3.6). */
    case Universal = 'universal';
    /** Not determinable from the subject and extensions: never guessed. */
    case Unknown = 'unknown';
}
