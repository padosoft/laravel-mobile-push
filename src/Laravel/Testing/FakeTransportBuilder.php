<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use PHPUnit\Framework\Assert;

/**
 * Verifies BYTES and BEHAVIOUR: the real pipeline (compilers, safe-mode guard, guards, classifier, retry planner,
 * events, log sinks, device registry updates) runs, and only the Transport is replaced by the core FakeTransport.
 *
 * Why this exists: outside production the default execution mode is `auto` = simulate, so a plain test would
 * never reach the transport. fakeTransport() therefore forces live mode and allows every destination while it
 * is active (the application environment reads as production inside the send pipeline).
 *
 * The answer helpers (apnsAccepted(), apnsError(), fcmAccepted(), fcmError(), connectionLost(), notSent()) only
 * BUILD answers; script them with sequence(), always() or respondUsing(). With no script every request is
 * accepted. Google's OAuth token exchange is answered automatically and never counted as a request.
 * Bearer tokens never appear in failure messages.
 */
final class FakeTransportBuilder
{
    private const TOKEN_HOST = 'oauth2.googleapis.com';

    private readonly FakeTransport $transport;

    /** @var list<Response|TransportFailure|\Closure(Request): (Response|TransportFailure|null)> */
    private array $script = [];

    /** @var (\Closure(Request): (Response|TransportFailure|null))|null */
    private ?\Closure $responder = null;

    private Response|TransportFailure|null $always = null;

    public function __construct()
    {
        $this->transport = new FakeTransport;
        $this->transport->respondUsing(fn (Request $request, ConnectionProfile $profile): Response|TransportFailure => $this->answer($request));
    }

    /** @internal The transport the pipeline sends through. */
    public function transport(): FakeTransport
    {
        return $this->transport;
    }

    // ---------------------------------------------------------------- answers (pure: they only build)

    public function apnsAccepted(): Response
    {
        return new Response(200, ['apns-id' => self::uuid('apns-accepted')], '');
    }

    /** An APNs error answer: `$timestampMs` is the "invalid since" time APNs sends with a 410. */
    public function apnsError(int $status, string $reason, ?int $timestampMs = null): Response
    {
        $body = ['reason' => $reason];
        if ($timestampMs !== null) {
            $body['timestamp'] = $timestampMs;
        }

        return new Response($status, ['apns-id' => self::uuid('apns-error')], (string) json_encode($body));
    }

    public function fcmAccepted(): Response
    {
        return new Response(200, ['content-type' => 'application/json'], '{"name":"projects/fake-project/messages/0:1700000000000000%fake"}');
    }

    /**
     * An FCM v1 error answer. `$errorCode` is the FcmError code (UNREGISTERED, INVALID_ARGUMENT, UNAVAILABLE,
     * QUOTA_EXCEEDED, SENDER_ID_MISMATCH, THIRD_PARTY_AUTH_ERROR, INTERNAL ...); `$retryAfter` seconds goes into
     * the Retry-After header.
     */
    public function fcmError(int $status, string $errorCode, ?int $retryAfter = null): Response
    {
        $rpc = match ($errorCode) {
            'UNREGISTERED' => 'NOT_FOUND',
            'QUOTA_EXCEEDED' => 'RESOURCE_EXHAUSTED',
            'SENDER_ID_MISMATCH' => 'PERMISSION_DENIED',
            'THIRD_PARTY_AUTH_ERROR' => 'UNAUTHENTICATED',
            'UNAVAILABLE', 'INTERNAL', 'INVALID_ARGUMENT' => $errorCode,
            default => 'UNKNOWN',
        };
        $headers = ['content-type' => 'application/json'];
        if ($retryAfter !== null) {
            $headers['retry-after'] = (string) $retryAfter;
        }
        $error = [
            'code' => $status,
            'message' => 'Fake FCM error '.$errorCode,
            'status' => $rpc,
            'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => $errorCode]],
        ];

        return new Response($status, $headers, (string) json_encode(['error' => $error]));
    }

    /** The connection dropped after the request was written: the provider may or may not have processed it (OutcomeUnknown). */
    public function connectionLost(): TransportFailure
    {
        return new TransportFailure(TransportEvidence::Unknown, 92, 'HTTP/2 stream was not closed cleanly: connection lost after the request was written');
    }

    /** Proven not sent: the connection could not even be opened (nothing reached the provider). */
    public function notSent(): TransportFailure
    {
        return new TransportFailure(TransportEvidence::NotSent, 7, 'Failed to connect: connection refused', false);
    }

    // ---------------------------------------------------------------- scripting

    /**
     * Answers consumed in order, one per request (a callback receives the Request and may return null to fall
     * through). When the list is exhausted, always() or the default (accepted) applies.
     *
     * @param  list<Response|TransportFailure|callable(Request): (Response|TransportFailure|null)>  $answers
     */
    public function sequence(array $answers): static
    {
        foreach ($answers as $answer) {
            $this->script[] = $answer instanceof Response || $answer instanceof TransportFailure ? $answer : \Closure::fromCallable($answer);
        }

        return $this;
    }

    /** The answer to every request the script does not cover (replaces the default "accepted"). */
    public function always(Response|TransportFailure $answer): static
    {
        $this->always = $answer;

        return $this;
    }

    /**
     * Decide per request; return null to fall through to sequence() / always() / the default.
     *
     * @param  callable(Request): (Response|TransportFailure|null)  $responder
     */
    public function respondUsing(callable $responder): static
    {
        $this->responder = \Closure::fromCallable($responder);

        return $this;
    }

    // ---------------------------------------------------------------- reading

    /**
     * What was put on the wire towards a provider, in order (the Google OAuth exchange is excluded).
     *
     * @return list<Request>
     */
    public function requests(): array
    {
        return array_values(array_filter($this->transport->requests(), static fn (Request $r): bool => ! self::isTokenExchange($r)));
    }

    /**
     * Every APNs request decoded.
     *
     * @return list<array{headers: array<string, string>, payload: array<mixed>, url: string}>
     */
    public function apnsRequests(): array
    {
        return array_map(self::decodeApns(...), array_values(array_filter($this->requests(), self::isApns(...))));
    }

    /**
     * Every FCM request decoded.
     *
     * @return list<array{message: array<mixed>, validateOnly: bool}>
     */
    public function fcmRequests(): array
    {
        return array_map(self::decodeFcm(...), array_values(array_filter($this->requests(), self::isFcm(...))));
    }

    /** @return array{headers: array<string, string>, payload: array<mixed>, url: string} authorization is redacted */
    public static function decodeApns(Request $request): array
    {
        return ['headers' => Describe::headers($request->headers), 'payload' => self::json($request->body), 'url' => $request->url];
    }

    /** @return array{message: array<mixed>, validateOnly: bool} */
    public static function decodeFcm(Request $request): array
    {
        $body = self::json($request->body);
        $message = $body['message'] ?? [];

        return ['message' => is_array($message) ? $message : [], 'validateOnly' => ($body['validate_only'] ?? false) === true];
    }

    // ---------------------------------------------------------------- assertions

    public function assertRequestCount(int $count): void
    {
        $requests = $this->requests();
        Assert::assertCount($count, $requests, sprintf("Expected %d request(s) on the wire, %d were sent:\n%s", $count, count($requests), self::list($requests)));
    }

    public function assertNoRequests(): void
    {
        $this->assertRequestCount(0);
    }

    public function assertSentToHost(string $host): void
    {
        $hosts = array_values(array_unique(array_map(static fn (Request $r): string => (string) parse_url($r->url, PHP_URL_HOST), $this->requests())));
        Assert::assertContains($host, $hosts, sprintf('No request was sent to %s. Hosts reached: %s', $host, $hosts === [] ? '(none)' : implode(', ', $hosts)));
    }

    /**
     * An APNs request matching fn (array $headers, array $payload, string $url): bool was sent (exactly `$times`
     * when given). Header names are lowercase; the authorization header is redacted.
     *
     * @param  callable(array<string, string>, array<mixed>, string): bool  $matcher
     */
    public function assertApnsRequest(callable $matcher, ?int $times = null): void
    {
        $all = array_values(array_filter($this->requests(), self::isApns(...)));
        $matched = 0;
        foreach ($all as $request) {
            $d = self::decodeApns($request);
            if ($matcher($d['headers'], $d['payload'], $d['url']) === true) {
                $matched++;
            }
        }
        self::assertMatched($matched, $times, 'APNs', $all);
    }

    /**
     * An FCM request matching fn (array $message, bool $validateOnly): bool was sent (exactly `$times` when given).
     *
     * @param  callable(array<mixed>, bool): bool  $matcher
     */
    public function assertFcmRequest(callable $matcher, ?int $times = null): void
    {
        $all = array_values(array_filter($this->requests(), self::isFcm(...)));
        $matched = 0;
        foreach ($all as $request) {
            $d = self::decodeFcm($request);
            if ($matcher($d['message'], $d['validateOnly']) === true) {
                $matched++;
            }
        }
        self::assertMatched($matched, $times, 'FCM', $all);
    }

    // ---------------------------------------------------------------- internals

    private function answer(Request $request): Response|TransportFailure
    {
        if (self::isTokenExchange($request)) {
            return new Response(200, ['content-type' => 'application/json'], (string) json_encode(['access_token' => 'fake-access-token', 'expires_in' => 3600, 'token_type' => 'Bearer']), '1.1');
        }

        if ($this->responder !== null) {
            $answer = ($this->responder)($request);
            if ($answer !== null) {
                return $answer;
            }
        }
        while ($this->script !== []) {
            $next = array_shift($this->script);
            $answer = $next instanceof \Closure ? $next($request) : $next;
            if ($answer !== null) {
                return $answer;
            }
        }

        return $this->always ?? (self::isFcm($request) ? $this->fcmAccepted() : $this->apnsAccepted());
    }

    /** @param  list<Request>  $all */
    private static function assertMatched(int $matched, ?int $times, string $provider, array $all): void
    {
        if ($times === null) {
            Assert::assertGreaterThan(0, $matched, sprintf("No %s request matched.\n%s requests sent:\n%s", $provider, $provider, self::list($all, true)));

            return;
        }
        Assert::assertSame($times, $matched, sprintf("Expected %d matching %s request(s), %d matched.\n%s requests sent:\n%s", $times, $provider, $matched, $provider, self::list($all, true)));
    }

    /** @param  list<Request>  $requests */
    private static function list(array $requests, bool $withBody = false): string
    {
        $lines = [];
        foreach ($requests as $request) {
            $line = '  - '.Describe::request($request);
            if ($withBody) {
                $headers = Describe::headers($request->headers);
                $keep = array_intersect_key($headers, array_flip(['apns-push-type', 'apns-topic', 'apns-priority', 'apns-expiration', 'apns-collapse-id', 'authorization']));
                $line .= ' '.json_encode($keep).' '.substr($request->body, 0, 500);
            }
            $lines[] = $line;
        }

        return $lines === [] ? '  (none)' : implode("\n", $lines);
    }

    private static function isTokenExchange(Request $request): bool
    {
        return parse_url($request->url, PHP_URL_HOST) === self::TOKEN_HOST;
    }

    private static function isApns(Request $request): bool
    {
        return str_ends_with((string) parse_url($request->url, PHP_URL_HOST), 'push.apple.com') || str_contains((string) parse_url($request->url, PHP_URL_HOST), 'apple.com');
    }

    private static function isFcm(Request $request): bool
    {
        return parse_url($request->url, PHP_URL_HOST) === 'fcm.googleapis.com';
    }

    /** @return array<mixed> */
    private static function json(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function uuid(string $seed): string
    {
        $h = hash('sha256', $seed);

        return sprintf('%s-%s-4%s-8%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
    }
}
