<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Core\Privacy\TokenMasker;

/** How destinations and provider text are printed: tokens masked, provider text stripped of anything token-like. */
final class Display
{
    /** An individual destination is masked; a topic, condition or channel id is a name, not a secret. */
    public static function destination(Destination $destination): string
    {
        return $destination->kind()->isIndividual() ? TokenMasker::mask($destination->value()) : $destination->value();
    }

    public static function reason(Reason $reason, Redactor $redactor): string
    {
        $raw = $reason->rawReason !== null && $reason->rawReason !== '' ? ' ('.$redactor->providerMessage($reason->rawReason).')' : '';

        return $reason->code->value.$raw;
    }

    public static function hint(Reason $reason, Redactor $redactor): string
    {
        return $reason->hint === null ? '' : $redactor->providerMessage($reason->hint);
    }
}
