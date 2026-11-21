<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Topics;

use Padosoft\MobilePush\Core\Auth\AccessTokenProvider;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\FcmFid;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
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
use Padosoft\MobilePush\Core\Transport\TransportFailure;

/**
 * FCM topic subscriptions, one installation per call, exactly as the Discovery document (revision 20261112,
 * resource projects.registrations.topicSubscriptions) describes them. The legacy Instance ID API is not used and no
 * bulk operation exists: the *Many() helpers multiplex single calls in one Transport::send().
 *
 *  create  POST   v1/{parent}/topicSubscriptions               query topicName (required), body TopicSubscription
 *                 "If the subscription already exists, returns error of ALREADY_EXISTS."
 *  get     GET    v1/{name}                                    name = projects/{p}/registrations/{r}/topicSubscriptions/{topic}
 *  list    GET    v1/{parent}/topicSubscriptions               query pageSize (default 1000, max 2000), pageToken
 *  patch   PATCH  v1/{name}                                    query allowMissing; "Returns an existing TopicSubscription or creates a new one"
 *  delete  DELETE v1/{name}                                    query allowMissing: "the request will succeed but no action will be taken"
 *  {registration} "can be an FID or an FCM Token".
 *
 * Semantics decided here:
 *  - subscribe(): ALREADY_EXISTS (HTTP 409) is success, reported through TopicSubscription::$alreadyExisted.
 *  - unsubscribe(): always sends allowMissing=true, so "already unsubscribed" is success (the server cannot say which case it was).
 *  - patch(): allowMissing defaults to true, an upsert that never fails on an existing subscription.
 *  - 401: the access token is refreshed once and the request retried once; no loop.
 *  - Errors never copy Google's `error.message` (it can echo the registration token).
 *
 * `$gate` asks SafeModeGuard (OperationKind::TopicSubscription, with the installation as destination) BEFORE any
 * connection; null means the caller has already decided. A refusal (simulate/blocked) throws TopicApiException from the
 * single calls and becomes a failed TopicBatchResult in the *Many() helpers; the Transport is never touched.
 */
final class TopicSubscriptionClient
{
    public const MAX_PAGE_SIZE = 2000;

    /** @var (\Closure(OperationKind, Destination): SafeModeDecision)|null */
    private readonly ?\Closure $gate;

    /**
     * @param  (\Closure(OperationKind, Destination): SafeModeDecision)|null  $gate
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly ConnectionProfile $profile,
        private readonly EndpointCatalog $endpoints,
        private readonly string $projectId,
        private readonly AccessTokenProvider $tokens,
        ?\Closure $gate = null,
    ) {
        $this->gate = $gate;
    }

    /** @throws TopicApiException */
    public function subscribe(#[\SensitiveParameter] FcmToken|FcmFid $installation, FcmTopic $topic): TopicSubscription
    {
        $url = $this->collectionUrl($installation).'?topicName='.rawurlencode($topic->value());
        [$response] = $this->single('POST', $url, '{}', $installation, allowConflict: true);

        if (TopicReasonMapper::isAlreadyExists($response)) {
            return new TopicSubscription($topic->value(), null, true);
        }

        return self::parse($response->json(), $topic);
    }

    /** @throws TopicApiException a missing subscription is HTTP 404 (TopicApiException::isNotFound()) */
    public function get(#[\SensitiveParameter] FcmToken|FcmFid $installation, FcmTopic $topic): TopicSubscription
    {
        [$response] = $this->single('GET', $this->itemUrl($installation, $topic), '', $installation);

        return self::parse($response->json(), $topic);
    }

    /**
     * All subscriptions of the installation, page after page (the next request is sent only when the generator is advanced).
     *
     * @return \Generator<int, TopicSubscription>
     *
     * @throws TopicApiException
     */
    public function list(#[\SensitiveParameter] FcmToken|FcmFid $installation, ?int $pageSize = null): \Generator
    {
        if ($pageSize !== null && ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE)) {
            throw new ConfigurationException('The page size must be between 1 and '.self::MAX_PAGE_SIZE.'.');
        }

        $this->guard($installation);

        return $this->pages($installation, $pageSize);
    }

    /**
     * Upsert: with allowMissing (default) an existing subscription is returned and a missing one is created.
     *
     * @throws TopicApiException
     */
    public function patch(#[\SensitiveParameter] FcmToken|FcmFid $installation, FcmTopic $topic, bool $allowMissing = true): TopicSubscription
    {
        $url = $this->itemUrl($installation, $topic).($allowMissing ? '?allowMissing=true' : '');
        [$response] = $this->single('PATCH', $url, '{}', $installation);

        return self::parse($response->json(), $topic);
    }

    /**
     * Idempotent: a subscription that is already gone is not an error.
     *
     * @throws TopicApiException
     */
    public function unsubscribe(#[\SensitiveParameter] FcmToken|FcmFid $installation, FcmTopic $topic): void
    {
        $this->single('DELETE', $this->itemUrl($installation, $topic).'?allowMissing=true', '', $installation);
    }

    /**
     * Subscribe many installations to one topic: one multiplexed send(), one result per input (same order, no exceptions).
     *
     * @param  iterable<FcmToken|FcmFid>  $installations
     * @return list<TopicBatchResult>
     */
    public function subscribeMany(#[\SensitiveParameter] iterable $installations, FcmTopic $topic): array
    {
        return $this->many($installations, $topic, subscribe: true);
    }

    /**
     * @param  iterable<FcmToken|FcmFid>  $installations
     * @return list<TopicBatchResult>
     */
    public function unsubscribeMany(#[\SensitiveParameter] iterable $installations, FcmTopic $topic): array
    {
        return $this->many($installations, $topic, subscribe: false);
    }

    /**
     * @return \Generator<int, TopicSubscription>
     */
    private function pages(#[\SensitiveParameter] FcmToken|FcmFid $installation, ?int $pageSize): \Generator
    {
        $base = $this->collectionUrl($installation);
        $pageToken = null;
        $seen = [];

        do {
            $query = [];

            if ($pageSize !== null) {
                $query[] = 'pageSize='.$pageSize;
            }

            if ($pageToken !== null) {
                $query[] = 'pageToken='.rawurlencode($pageToken);
            }

            [$response] = $this->single('GET', $base.($query === [] ? '' : '?'.implode('&', $query)), '', $installation, gate: false);
            $json = $response->json();
            $items = is_array($json) && is_array($json['topicSubscriptions'] ?? null) ? $json['topicSubscriptions'] : [];

            foreach ($items as $item) {
                if (is_array($item)) {
                    /** @var array<string, mixed> $item */
                    yield self::parse($item, null);
                }
            }

            $next = is_array($json) ? ($json['nextPageToken'] ?? null) : null;
            $pageToken = is_string($next) && $next !== '' && ! isset($seen[$next]) ? $next : null;

            if ($pageToken !== null) {
                $seen[$pageToken] = true;
            }
        } while ($pageToken !== null);
    }

    /**
     * @param  iterable<FcmToken|FcmFid>  $installations
     * @return list<TopicBatchResult>
     */
    private function many(#[\SensitiveParameter] iterable $installations, FcmTopic $topic, bool $subscribe): array
    {
        $results = [];
        $specs = [];
        $operation = OperationKind::TopicSubscription;
        $index = 0;

        foreach ($installations as $installation) {
            $i = $index++;

            try {
                $decision = $this->gate !== null ? ($this->gate)($operation, $installation) : null;

                if ($decision !== null && ! $decision->allowed) {
                    $results[$i] = TopicBatchResult::failed(0, $decision->reason());

                    continue;
                }

                $specs[$i] = $subscribe
                    ? ['method' => 'POST', 'url' => $this->collectionUrl($installation).'?topicName='.rawurlencode($topic->value()), 'body' => '{}']
                    : ['method' => 'DELETE', 'url' => $this->itemUrl($installation, $topic).'?allowMissing=true', 'body' => ''];
            } catch (ConfigurationException) {
                $results[$i] = TopicBatchResult::failed(0, new Reason(ReasonCode::RequestInvalid, null, 'The installation id cannot be used in a URL.'));
            }
        }

        if ($specs !== []) {
            foreach ($this->run($specs) as $i => $exchange) {
                $response = $exchange->response;

                if ($response === null) {
                    $results[$i] = TopicBatchResult::failed(0, self::transportReason($exchange->failure));
                } elseif ($response->status >= 200 && $response->status <= 299) {
                    $results[$i] = TopicBatchResult::done($response->status);
                } elseif ($subscribe && TopicReasonMapper::isAlreadyExists($response)) {
                    $results[$i] = TopicBatchResult::done($response->status, alreadyInState: true);
                } else {
                    $results[$i] = TopicBatchResult::failed($response->status, TopicReasonMapper::reason($response));
                }
            }
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * @return array{Response}
     */
    private function single(string $method, #[\SensitiveParameter] string $url, string $body, #[\SensitiveParameter] FcmToken|FcmFid $installation, bool $allowConflict = false, bool $gate = true): array
    {
        if ($gate) {
            $this->guard($installation);
        }

        $exchange = $this->run([0 => ['method' => $method, 'url' => $url, 'body' => $body]])[0];
        $response = $exchange->response;

        if ($response === null) {
            throw TopicApiException::transport(self::transportReason($exchange->failure));
        }

        if (($response->status < 200 || $response->status > 299) && ! ($allowConflict && TopicReasonMapper::isAlreadyExists($response))) {
            throw TopicApiException::fromResponse($response->status, TopicReasonMapper::reason($response));
        }

        return [$response];
    }

    private function guard(#[\SensitiveParameter] Destination $installation): void
    {
        $decision = $this->gate !== null ? ($this->gate)(OperationKind::TopicSubscription, $installation) : null;

        if ($decision !== null && ! $decision->allowed) {
            throw TopicApiException::refused($decision);
        }
    }

    /**
     * Send every spec in one multiplexed send(); requests answered 401 are retried once, together, with a refreshed token.
     *
     * @param  array<int, array{method: string, url: string, body: string}>  $specs
     * @return array<int, Exchange>
     */
    private function run(#[\SensitiveParameter] array $specs): array
    {
        $token = $this->tokens->token();
        $done = $this->dispatch($specs, $token->value);
        $expired = array_keys(array_filter($done, static fn (Exchange $e): bool => $e->response?->status === 401));

        if ($expired !== []) {
            $fresh = $this->tokens->refreshAfterRejection($token);
            $retry = [];

            foreach ($expired as $i) {
                $retry[$i] = $specs[$i];
            }

            $done = $this->dispatch($retry, $fresh->value) + $done;
        }

        return $done;
    }

    /**
     * @param  array<int, array{method: string, url: string, body: string}>  $specs
     * @return array<int, Exchange>
     */
    private function dispatch(#[\SensitiveParameter] array $specs, #[\SensitiveParameter] string $bearer): array
    {
        $headers = ['authorization' => 'Bearer '.$bearer, 'content-type' => 'application/json'];
        $requests = [];

        foreach ($specs as $i => $spec) {
            $requests[$i] = new Request((string) $i, $spec['method'], $spec['url'], $headers, $spec['body']);
        }

        $exchanges = [];

        foreach ($this->transport->send($requests, $this->profile) as $id => $exchange) {
            $exchanges[(int) $id] = $exchange;
        }

        // The Transport contract yields one Exchange per request; a missing one is an unknown outcome, never a success.
        foreach ($requests as $i => $request) {
            $exchanges[$i] ??= Exchange::failed($request, new TransportFailure(TransportEvidence::Unknown, 0, 'No exchange was returned.'));
        }

        ksort($exchanges);

        return $exchanges;
    }

    private static function transportReason(?TransportFailure $failure): Reason
    {
        return $failure !== null && $failure->evidence === TransportEvidence::NotSent
            ? new Reason(ReasonCode::TransportNotSent, null, 'Nothing reached FCM; retrying is safe.')
            : new Reason(ReasonCode::TransportUnknown, null, 'The request may have been processed.');
    }

    private function collectionUrl(#[\SensitiveParameter] FcmToken|FcmFid $installation): string
    {
        $url = $this->endpoints->fcmTopicSubscriptionUrl($this->projectId, $installation->value());
        $this->endpoints->assertOfficial($url, EndpointRole::FcmApi);

        return $url;
    }

    /** The item path `.../topicSubscriptions/{topic}`, built and validated by the catalog. */
    private function itemUrl(#[\SensitiveParameter] FcmToken|FcmFid $installation, FcmTopic $topic): string
    {
        $url = $this->endpoints->fcmTopicSubscriptionItemUrl($this->projectId, $installation->value(), $topic->value());
        $this->endpoints->assertOfficial($url, EndpointRole::FcmApi);

        return $url;
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private static function parse(?array $json, ?FcmTopic $fallback): TopicSubscription
    {
        $name = is_string($json['topicName'] ?? null) ? $json['topicName'] : null;

        if ($name === null && is_string($json['name'] ?? null)) {
            $name = rawurldecode(substr((string) strrchr($json['name'], '/'), 1));
        }

        $name ??= $fallback?->value() ?? '';
        $created = null;

        if (is_string($json['createTime'] ?? null)) {
            try {
                $created = new \DateTimeImmutable($json['createTime']);
            } catch (\Exception) {
                $created = null;
            }
        }

        return new TopicSubscription($name, $created);
    }
}
