<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Message\CompileContext;
use Padosoft\MobilePush\Core\Message\CompiledMessage;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\MessageCompiler;

/** Compiles nothing: the intent fake verifies what the application asked for, not the bytes. @internal */
final readonly class FakeCompiler implements MessageCompiler
{
    public function __construct(private PushFake $fake) {}

    public function compile(Message $message, Destination $destination, CompileContext $context): CompiledMessage
    {
        $this->fake->pinned($destination, $context->expiresAt, $context->validateOnly);

        return new CompiledMessage([], '{}', 2, PHP_INT_MAX);
    }

    public function supports(Message $message): bool
    {
        return true;
    }
}
