<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\ApnsDriver;
use Padosoft\MobilePush\Core\Auth\AccessTokenProvider;
use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Driver\Driver;
use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsLiveActivityToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Message\CompiledMessage;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\MessageCompiler;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\OutcomeClassifier;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Policy\EndpointRole;
use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Stories about destinations that cannot be prepared: a wrong destination, an oversized one, a receipt-marker
 * callback that breaks, a request that cannot be built. One destination's problem is that destination's result;
 * only a message that is invalid as such, detected on the very first destination, throws.
 */

/** The real APNs driver, except that building the request for some device tokens fails (a driver bug). */
final class RequestBuildFailingDriver implements Driver
{
    /** @param  list<string>  $failFor  destination values */
    public function __construct(private readonly ApnsDriver $inner, private readonly array $failFor) {}

    public function binding(): BindingRef
    {
        return $this->inner->binding();
    }

    public function compiler(): MessageCompiler
    {
        return $this->inner->compiler();
    }

    public function classifier(): OutcomeClassifier
    {
        return $this->inner->classifier();
    }

    public function tokenProvider(int $generation): ?AccessTokenProvider
    {
        return $this->inner->tokenProvider($generation);
    }

    public function hasSecondaryCredential(): bool
    {
        return $this->inner->hasSecondaryCredential();
    }

    public function credentialIdentity(): string
    {
        return $this->inner->credentialIdentity();
    }

    public function credentialIdentityFor(int $generation): string
    {
        return $this->inner->credentialIdentityFor($generation);
    }

    public function accountKey(): string
    {
        return $this->inner->accountKey();
    }

    public function supportsValidateOnly(): bool
    {
        return $this->inner->supportsValidateOnly();
    }

    public function operationKind(Message $message, Destination $destination, bool $validateOnly): OperationKind
    {
        return $this->inner->operationKind($message, $destination, $validateOnly);
    }

    public function isReplaceable(Message $message): bool
    {
        return $this->inner->isReplaceable($message);
    }

    public function endpointRole(CompiledMessage $compiled): EndpointRole
    {
        return $this->inner->endpointRole($compiled);
    }

    public function connectionProfile(EndpointRole $role, int $generation): ConnectionProfile
    {
        return $this->inner->connectionProfile($role, $generation);
    }

    public function window(): int
    {
        return $this->inner->window();
    }

    public function buildRequest(string $requestId, CompiledMessage $compiled, Destination $destination, ?AuthToken $token): Request
    {
        if (in_array($destination->value(), $this->failFor, true)) {
            throw new UnexpectedValueException('header table overflow');
        }

        return $this->inner->buildRequest($requestId, $compiled, $destination, $token);
    }
}

it('rejects a first destination of the wrong kind instead of treating it as an invalid message', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;

    $results = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('A', 'B'), [ApnsLiveActivityToken::of(str_repeat('0e', 32)), SendKit::apnsToken(1)], SendKit::live()));

    expect(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Rejected, SendStatus::Accepted])
        ->and($results[0]->reason->code)->toBe(ReasonCode::RequestInvalid)
        ->and($transport->requests())->toHaveCount(1);
});

it('rejects a first destination that is too large for its kind and sends the rest', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->defaultTo(SendKit::fcmOk());
    $message = PushMessage::make('Promotion', str_repeat('Great deal! ', 250));   // fits a token, not a topic

    $results = SendKit::all($kit->sender($transport)->send($kit->fcmDriver(), $message, [FcmTopic::of('deals'), SendKit::fcmToken(1)], SendKit::live()));

    expect(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Rejected, SendStatus::Accepted])
        ->and($results[0]->reason->code)->toBe(ReasonCode::PayloadTooLarge)
        ->and($transport->requests())->toHaveCount(1);
});

it('throws for a globally invalid message sent to a single destination', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;

    expect(fn () => $kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('Visible title')->silent(), [SendKit::apnsToken(1)], SendKit::live()))
        ->toThrow(InvalidMessageException::class);
    expect($transport->requests())->toBe([]);
});

/*
 * Story: the app already uses the custom key the receipt marker is written under. The callback only asks for a
 * marker for some devices: the first one compiles, the second one cannot carry the marker. That device is
 * rejected as an invalid payload; nothing throws, and the other devices are sent.
 */
it('rejects as an invalid payload a later destination whose receipt marker collides with the message data', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $second = SendKit::apnsToken(2)->value();
    $marker = static fn (Destination $d, string $id): ?string => $d->value() === $second ? 'receipt-abc123' : null;

    $results = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('A', 'B')->data(['mp_r' => 'app-owned']), SendKit::apnsTokens(3, 1), SendKit::live(receiptMarker: $marker)));

    expect(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Accepted, SendStatus::Rejected, SendStatus::Accepted])
        ->and($results[1]->reason->code)->toBe(ReasonCode::PayloadInvalid)
        ->and($results[1]->attempts)->toBe(0)
        ->and($transport->requests())->toHaveCount(2);
});

/*
 * Story: the receipt-marker callback of the application throws for one device. That is not a validation error:
 * the device is reported Failed (nothing was sent for it) and the batch goes on.
 */
it('fails only the destination whose receipt-marker callback throws, after the first one', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $second = SendKit::apnsToken(2)->value();
    $marker = static function (Destination $d, string $id) use ($second): ?string {
        if ($d->value() === $second) {
            throw new RuntimeException('marker store unavailable');
        }

        return null;
    };

    $results = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('A', 'B'), SendKit::apnsTokens(3, 1), SendKit::live(receiptMarker: $marker)));

    expect(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Accepted, SendStatus::Failed, SendStatus::Accepted])
        ->and($results[1]->reason->code)->toBe(ReasonCode::RequestInvalid)
        ->and($results[1]->reason->hint)->toContain('RuntimeException')->toContain('marker store unavailable')
        ->and($results[1]->attempts)->toBe(0)
        ->and($transport->requests())->toHaveCount(2);
});

it('fails only the destination whose request cannot be built, and sends the others of the window', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $driver = new RequestBuildFailingDriver($kit->apnsDriver(), [SendKit::apnsToken(1)->value()]);

    $results = SendKit::all($kit->sender($transport)->send($driver, PushMessage::make('A', 'B'), SendKit::apnsTokens(3), SendKit::live()));

    expect(array_map(fn ($r) => $r->status, $results))->toBe([SendStatus::Accepted, SendStatus::Failed, SendStatus::Accepted])
        ->and($results[1]->reason->code)->toBe(ReasonCode::RequestInvalid)
        ->and($results[1]->reason->hint)->toContain('UnexpectedValueException')
        ->and(array_map(fn (Request $r) => SendKit::tokenOf($r), $transport->requests()))
        ->toBe([SendKit::apnsToken(0)->value(), SendKit::apnsToken(2)->value()]);
});

it('does not throw when the request of the very first destination cannot be built', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $driver = new RequestBuildFailingDriver($kit->apnsDriver(), [SendKit::apnsToken(0)->value(), SendKit::apnsToken(1)->value()]);

    $results = SendKit::all($kit->sender($transport)->send($driver, PushMessage::make('A', 'B'), SendKit::apnsTokens(2), SendKit::live()));

    expect(array_map(fn ($r) => $r->status, $results))->each->toBe(SendStatus::Failed)
        ->and($transport->requests())->toBe([]);
});

/*
 * Story: a batch made only of destinations the message cannot reach. Every one is rejected at compilation, so
 * no provider token is minted for it: signing a JWT costs a key operation and Apple limits how often it may change.
 */
it('mints no provider token for a pass whose destinations were all rejected at compilation', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $activities = [ApnsLiveActivityToken::of(str_repeat('0a', 32)), ApnsLiveActivityToken::of(str_repeat('0b', 32))];

    $results = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('A', 'B'), $activities, SendKit::live()));

    expect(array_map(fn ($r) => $r->status, $results))->each->toBe(SendStatus::Rejected)
        ->and($kit->signer('KEY1111111')->signatures)->toBe(0)
        ->and($transport->requests())->toBe([]);
});
