<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

use Padosoft\MobilePush\Core\Auth\AccessTokenProvider;
use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Driver\Driver;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\CompiledMessage;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\MessageCompiler;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\OutcomeClassifier;
use Padosoft\MobilePush\Core\Policy\EndpointRole;
use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\Request;

/**
 * The driver of a fake connection: no credentials, no compilation, no wire format. It keeps the provider rules the
 * application can trip over (APNs has no validate-only mode, destinations belong to one provider) and nothing else.
 *
 * @internal
 */
final class FakeDriver implements Driver
{
    private readonly FakeCompiler $compiler;

    private readonly FakeClassifier $classifier;

    public function __construct(private readonly BindingRef $binding, PushFake $fake)
    {
        $this->compiler = new FakeCompiler($fake, $binding->provider);
        $this->classifier = new FakeClassifier($fake);
    }

    public function binding(): BindingRef
    {
        return $this->binding;
    }

    public function compiler(): MessageCompiler
    {
        return $this->compiler;
    }

    public function classifier(): OutcomeClassifier
    {
        return $this->classifier;
    }

    public function tokenProvider(int $generation): ?AccessTokenProvider
    {
        return null;
    }

    public function hasSecondaryCredential(): bool
    {
        return false;
    }

    public function credentialIdentity(): string
    {
        return 'fake:'.$this->binding->name;
    }

    public function credentialIdentityFor(int $generation): string
    {
        return $this->credentialIdentity();
    }

    public function accountKey(): string
    {
        return 'fake:'.$this->binding->provider->value.':'.$this->binding->appId;
    }

    public function supportsValidateOnly(): bool
    {
        return $this->binding->provider === Provider::Fcm;
    }

    public function operationKind(Message $message, Destination $destination, bool $validateOnly): OperationKind
    {
        if ($this->binding->provider === Provider::Apns) {
            return $destination->kind() === DestinationKind::ApnsBroadcastChannel ? OperationKind::BroadcastPublish : OperationKind::Send;
        }

        return $validateOnly ? OperationKind::Validate : OperationKind::Send;
    }

    public function isReplaceable(Message $message): bool
    {
        return $message instanceof PushMessage && ($message->replaceable || $message->silent);
    }

    public function endpointRole(CompiledMessage $compiled): EndpointRole
    {
        return $this->binding->provider === Provider::Fcm ? EndpointRole::FcmApi : EndpointRole::ApnsSend;
    }

    public function connectionProfile(EndpointRole $role, int $generation): ConnectionProfile
    {
        return new ConnectionProfile('fake:'.$this->binding->name.':'.$role->value);
    }

    public function window(): int
    {
        return 100;
    }

    public function buildRequest(string $requestId, CompiledMessage $compiled, Destination $destination, ?AuthToken $token): Request
    {
        return new Request($requestId, 'POST', 'https://fake.mobile-push.invalid/'.$this->binding->name);
    }
}
