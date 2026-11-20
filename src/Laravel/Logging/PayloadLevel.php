<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Logging;

/** How much of the message payload a log record may carry. */
enum PayloadLevel: string
{
    /** Nothing (default). */
    case None = 'none';
    /** Only the key names of the payload (dotted paths), never a value. */
    case Keys = 'keys';
    /** The redacted payload. Explicit opt-in. */
    case Full = 'full';
}
