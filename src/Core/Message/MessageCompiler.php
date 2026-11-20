<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Message;

use Padosoft\MobilePush\Core\Exceptions\ValidationException;
use Padosoft\MobilePush\Core\Identity\Destination;

interface MessageCompiler
{
    /**
     * Compile a message for one destination. Pure: no I/O, no clock other than `$context->now`.
     *
     * Limits are checked AFTER overlays, encoding and receipt marker. Over-limit payloads are rejected
     * unless the message opted into truncation, which only ever shortens title/subtitle/body.
     *
     * @throws ValidationException invalid message, unsupported capability, wrong destination kind, payload too large
     */
    public function compile(Message $message, Destination $destination, CompileContext $context): CompiledMessage;

    public function supports(Message $message): bool;
}
