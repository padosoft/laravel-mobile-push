<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Driver;

use Padosoft\MobilePush\Core\Auth\AccessTokenProvider;
use Padosoft\MobilePush\Core\Auth\AuthToken;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Message\CompiledMessage;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\MessageCompiler;
use Padosoft\MobilePush\Core\Outcome\OutcomeClassifier;
use Padosoft\MobilePush\Core\Policy\EndpointRole;
use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\Request;

/**
 * The provider-specific glue for ONE app binding. Stateless apart from caches of derived values: everything
 * that decides (retry, guards, mode) lives in the Sender, and everything that interprets provider answers lives
 * in the classifier the driver exposes.
 *
 * Credential generations: 0 = primary, 1 = optional secondary (same team/project and environment). Which one is
 * active is decided by the authentication breaker, never by the driver.
 */
interface Driver
{
    public function binding(): BindingRef;

    public function compiler(): MessageCompiler;

    public function classifier(): OutcomeClassifier;

    /**
     * Bearer source of a credential generation; null when the generation authenticates with an mTLS client
     * certificate (which travels in the ConnectionProfile instead).
     *
     * @throws \InvalidArgumentException for a generation the driver does not have
     */
    public function tokenProvider(int $generation): ?AccessTokenProvider;

    public function hasSecondaryCredential(): bool;

    /**
     * Stable, non-secret id of the credential PAIR (primary + optional secondary), the same for both generations:
     * the authentication breaker is keyed by it, so the failover decision is taken once for everyone using it.
     */
    public function credentialIdentity(): string;

    /** Non-secret identity of one generation: team id + key id, certificate SHA-256 fingerprint, or Google principal. */
    public function credentialIdentityFor(int $generation): string;

    /** AccountKey::of(provider, team id | project id): shared by every alias of the same provider account. */
    public function accountKey(): string;

    /** Whether the provider can validate a request without sending it (FCM validate_only; APNs has no dry run). */
    public function supportsValidateOnly(): bool;

    public function operationKind(Message $message, Destination $destination, bool $validateOnly): OperationKind;

    /**
     * True when a later copy of the message replaces the earlier one on the device, so an unknown outcome may be
     * retried (Live Activity update/end, background, widgets/controls reloads, PushMessage::$replaceable).
     */
    public function isReplaceable(Message $message): bool;

    /** The endpoint role a compiled message is sent to (send vs broadcast publish use different pools). */
    public function endpointRole(CompiledMessage $compiled): EndpointRole;

    /** The connection profile (and pool key) for a role and credential generation. */
    public function connectionProfile(EndpointRole $role, int $generation): ConnectionProfile;

    /** Preferred number of requests multiplexed per window. */
    public function window(): int;

    /**
     * Turn a compiled message into a transport request. The URL comes only from the EndpointCatalog.
     * `$token` is the bearer of the generation in use (null with mTLS).
     */
    public function buildRequest(string $requestId, CompiledMessage $compiled, Destination $destination, ?AuthToken $token): Request;
}
