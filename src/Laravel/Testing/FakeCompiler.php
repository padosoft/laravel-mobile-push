<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

use Padosoft\MobilePush\Core\Apns\ApnsCompiler;
use Padosoft\MobilePush\Core\Fcm\FcmCompiler;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\CompileContext;
use Padosoft\MobilePush\Core\Message\CompiledMessage;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\MessageCompiler;

/**
 * The intent fake verifies what the application asked for, not the bytes: nothing compiled is kept. By default the
 * provider's real compiler still runs on every destination (validation only: the result is thrown away, no receipt
 * marker, no request), so a message the real provider path would refuse fails the faked test the same way.
 * PushFake::withoutValidation() turns that off.
 *
 * @internal
 */
final readonly class FakeCompiler implements MessageCompiler
{
    private MessageCompiler $real;

    public function __construct(private PushFake $fake, Provider $provider = Provider::Apns)
    {
        $this->real = $provider === Provider::Fcm ? new FcmCompiler : new ApnsCompiler;
    }

    public function compile(Message $message, Destination $destination, CompileContext $context): CompiledMessage
    {
        if ($this->fake->validates()) {
            $this->real->compile($message, $destination, $context);
        }
        if ($context->messageId !== null) {
            // A send attempt (a pre-flight compile carries no message id and is not one).
            $this->fake->pinned($destination, $context->expiresAt, $context->validateOnly);
        }

        return new CompiledMessage([], '{}', 2, PHP_INT_MAX);
    }

    public function supports(Message $message): bool
    {
        return ! $this->fake->validates() || $this->real->supports($message);
    }
}
