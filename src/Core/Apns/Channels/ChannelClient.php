<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns\Channels;

use Padosoft\MobilePush\Core\Auth\AccessTokenProvider;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Policy\EndpointCatalog;
use Padosoft\MobilePush\Core\Policy\EndpointRole;
use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Core\Policy\SafeModeDecision;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\Exchange;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;

/**
 * APNs broadcast channel management (create / read / delete / list).
 *
 * Apple, sending-channel-management-requests-to-apns (read 2026-11-20):
 *  - hosts (only through EndpointCatalog): sandbox api-manage-broadcast.sandbox.push.apple.com:2195,
 *    production api-manage-broadcast.push.apple.com:2196. "Channels can't be shared across environments".
 *  - create: POST /1/apps/{bundleId}/channels, body {"message-storage-policy": 0|1, "push-type": "LiveActivity"},
 *    201 with "A base64-encoded string that identifies the newly created channel" in the `apns-channel-id` response header, empty body.
 *  - read: GET same path with request header `apns-channel-id` -> 200 {"message-storage-policy","push-type"}.
 *  - delete: DELETE same path with `apns-channel-id` -> 204. "Deleting a channel is an irreversible action"; an id cannot be created again.
 *  - list: GET /1/apps/{bundleId}/all-channels -> 200 {"channels": ["<base64 id>", ...]}.
 *  - `apns-request-id` optional UUID, echoed back; authorization "bearer <provider_token>" (token auth) or mTLS.
 *  - "you can maintain up to 10,000 channels for your app in the development and production environment, respectively."
 *
 * The caller passes a ConnectionProfile for the channel-management role (never the send pool) and a `$gate` that
 * asks SafeModeGuard (OperationKind::ChannelManagement) BEFORE any connection; null means the caller has already decided.
 * When the gate refuses (simulate or blocked) nothing reaches the Transport and a ChannelApiException is thrown.
 */
final class ChannelClient
{
    private const BASE64_ID = '/^[A-Za-z0-9+\/=_-]{1,1024}$/';

    /** @var (\Closure(OperationKind): SafeModeDecision)|null */
    private readonly ?\Closure $gate;

    /**
     * @param  AccessTokenProvider|null  $tokens  null = mutual TLS (the certificate is in the ConnectionProfile)
     * @param  (\Closure(OperationKind): SafeModeDecision)|null  $gate
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly ConnectionProfile $profile,
        private readonly EndpointCatalog $endpoints,
        private readonly BindingRef $binding,
        private readonly ?AccessTokenProvider $tokens = null,
        ?\Closure $gate = null,
    ) {
        if ($binding->provider !== Provider::Apns) {
            throw new ConfigurationException('Channel management needs an APNs binding.');
        }

        $this->gate = $gate;
    }

    /**
     * @throws ChannelOutcomeUnknownException when the response was lost: the channel may exist (see its docblock)
     * @throws ChannelApiException
     */
    public function create(StoragePolicy $policy): Channel
    {
        $body = json_encode(['message-storage-policy' => $policy->value, 'push-type' => Channel::PUSH_TYPE_LIVE_ACTIVITY], JSON_THROW_ON_ERROR);
        $response = $this->call('POST', $this->channelsUrl(), ['content-type' => 'application/json'], $body, creating: true);
        $id = $response->header('apns-channel-id');

        if ($id === null || preg_match(self::BASE64_ID, $id) !== 1) {
            throw ChannelOutcomeUnknownException::forCreate($response->header('apns-request-id') ?? '', $response->status);
        }

        return new Channel($id, $policy);
    }

    /** @throws ChannelApiException */
    public function get(string $channelId): Channel
    {
        $response = $this->call('GET', $this->channelsUrl(), ['apns-channel-id' => self::channelId($channelId)]);
        $json = $response->json();
        $policy = is_array($json) && is_int($json['message-storage-policy'] ?? null) ? StoragePolicy::tryFrom($json['message-storage-policy']) : null;
        $type = is_array($json) ? ($json['push-type'] ?? null) : null;

        if ($policy === null || ! is_string($type)) {
            throw ChannelApiException::fromResponse($response->status, new Reason(ReasonCode::ResponseUnparseable), $response->header('apns-request-id'));
        }

        return new Channel($channelId, $policy, $type);
    }

    /** @throws ChannelApiException */
    public function delete(string $channelId): void
    {
        $this->call('DELETE', $this->channelsUrl(), ['apns-channel-id' => self::channelId($channelId)]);
    }

    /**
     * Every channel id of the binding's bundle in its environment. Apple documents no pagination.
     *
     * @return list<string>
     *
     * @throws ChannelApiException
     */
    public function all(): array
    {
        $url = $this->endpoints->apnsAllChannelsUrl($this->binding->environment, $this->binding->appId);
        $this->endpoints->assertOfficial($url, EndpointRole::ApnsChannelManagement);
        $response = $this->call('GET', $url);
        $json = $response->json();
        $channels = is_array($json) ? ($json['channels'] ?? null) : null;

        if (! is_array($channels)) {
            throw ChannelApiException::fromResponse($response->status, new Reason(ReasonCode::ResponseUnparseable), $response->header('apns-request-id'));
        }

        $ids = [];

        foreach ($channels as $id) {
            if (is_string($id) && preg_match(self::BASE64_ID, $id) === 1) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Compare the local list with APNs. Run it after a ChannelOutcomeUnknownException.
     *
     * @param  list<string>  $knownIds
     *
     * @throws ChannelApiException
     */
    public function reconcile(array $knownIds): ChannelReconciliation
    {
        $remote = $this->all();

        return new ChannelReconciliation(
            array_values(array_diff($remote, $knownIds)),
            array_values(array_diff($knownIds, $remote)),
        );
    }

    private function channelsUrl(): string
    {
        $url = $this->endpoints->apnsChannelsUrl($this->binding->environment, $this->binding->appId);
        $this->endpoints->assertOfficial($url, EndpointRole::ApnsChannelManagement);

        return $url;
    }

    private static function channelId(string $id): string
    {
        if (preg_match(self::BASE64_ID, $id) !== 1) {
            throw new InvalidDestinationException('An APNs channel id is a base64 string.');
        }

        return $id;
    }

    /**
     * @param  array<string, string>  $headers  without authorization and apns-request-id
     */
    private function call(string $method, string $url, #[\SensitiveParameter] array $headers = [], string $body = '', bool $creating = false): Response
    {
        if ($this->gate !== null) {
            $decision = ($this->gate)(OperationKind::ChannelManagement);

            if (! $decision->allowed) {
                throw ChannelApiException::refused($decision);
            }
        }

        $token = $this->tokens?->token();
        $exchange = $this->dispatch($method, $url, $headers, $body, $token?->value, $creating);

        // 403 ExpiredProviderToken: APNs rejected the request before acting, so refreshing once and retrying once is safe.
        if ($this->tokens !== null && $token !== null && $this->isExpiredToken($exchange)) {
            $fresh = $this->tokens->refreshAfterRejection($token);
            $exchange = $this->dispatch($method, $url, $headers, $body, $fresh->value, $creating);
        }

        $response = $exchange->response;

        if ($response === null) {
            throw new \LogicException('dispatch() returns only exchanges with a response.');
        }

        if ($response->status < 200 || $response->status > 299) {
            $json = $response->json();
            $raw = is_array($json) && is_string($json['reason'] ?? null) ? $json['reason'] : null;

            throw ChannelApiException::fromResponse(
                $response->status,
                ChannelReasonMapper::reason($response->status, $raw),
                $response->header('apns-request-id') ?? $exchange->request->id,
            );
        }

        return $response;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function dispatch(string $method, string $url, array $headers, string $body, #[\SensitiveParameter] ?string $bearer, bool $creating): Exchange
    {
        $id = self::uuid();
        $headers['apns-request-id'] = $id;

        if ($bearer !== null) {
            $headers['authorization'] = 'bearer '.$bearer;
        }

        $request = new Request($id, $method, $url, $headers, $body);
        $exchange = null;

        foreach ($this->transport->send([$request], $this->profile) as $candidate) {
            $exchange = $candidate;
        }

        $failure = $exchange?->failure;

        if ($exchange === null || $failure !== null) {
            $proven = $failure !== null && $failure->evidence === TransportEvidence::NotSent;

            if ($creating && ! $proven) {
                throw ChannelOutcomeUnknownException::forCreate($id);
            }

            throw ChannelApiException::transport(
                new Reason($proven ? ReasonCode::TransportNotSent : ReasonCode::TransportUnknown, null, $proven ? 'Nothing reached APNs; retrying is safe.' : 'The request may have been processed.'),
                $id,
            );
        }

        return $exchange;
    }

    private function isExpiredToken(Exchange $exchange): bool
    {
        $response = $exchange->response;

        if ($response === null || $response->status !== 403) {
            return false;
        }

        $json = $response->json();

        return is_array($json) && ($json['reason'] ?? null) === 'ExpiredProviderToken';
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
