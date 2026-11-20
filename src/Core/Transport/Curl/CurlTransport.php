<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Transport\Curl;

use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\Exchange;
use Padosoft\MobilePush\Core\Transport\HttpVersion;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\Transport;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;

/**
 * HTTP/2 (and HTTP/1.1) transport on curl_multi. See docs/transport.md for the measured behaviour behind every choice.
 *
 *  - one Exchange per request, never an exception mid-batch; safe when the consumer stops iterating early;
 *  - lazy input: requests are pulled from the iterable only when the window has room;
 *  - sliding window of in-flight requests = ConnectionProfile::$window (AIMD-reduced after refused streams);
 *  - persistent pools per pool key in a CurlPoolRegistry (LRU, idle expiry, fork safety);
 *  - request bodies are streamed through a read callback WITHOUT a seek callback, which makes libcurl's silent
 *    automatic re-send (REFUSED_STREAM, GOAWAY, reused connection died before any response byte) fail with
 *    CURLE_SEND_FAIL_REWIND instead of duplicating a request the server may already have processed.
 */
final class CurlTransport implements Transport
{
    /** Responses larger than this are aborted (a push API never sends more). */
    public const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;

    /** Message prefix of the failure reported when HTTP/2 is required but HTTP/1.x was negotiated. */
    public const HTTP2_REQUIRED = 'HTTP/2 was required';

    private const TCP_KEEPIDLE_SECONDS = 30;

    private const TCP_KEEPINTVL_SECONDS = 15;

    private static int $nextOwner = 0;

    private readonly CurlPoolRegistry $pools;

    private readonly CurlCapabilities $capabilities;

    public function __construct(?CurlPoolRegistry $pools = null, ?CurlCapabilities $capabilities = null)
    {
        $this->pools = $pools ?? CurlPoolRegistry::process();
        $this->capabilities = $capabilities ?? CurlCapabilities::detect();
    }

    public function send(iterable $requests, ConnectionProfile $profile): \Generator
    {
        // Throws TransportConfigurationException here, at call time, before anything is sent.
        $this->capabilities->assertSupports($profile);

        return $this->stream($requests, $profile);
    }

    public function close(?string $poolKey = null): void
    {
        $this->pools->close($poolKey);
    }

    public function registry(): CurlPoolRegistry
    {
        return $this->pools;
    }

    /**
     * @param  iterable<Request>  $requests
     * @return \Generator<string, Exchange>
     */
    private function stream(iterable $requests, ConnectionProfile $profile): \Generator
    {
        $pool = $this->pools->acquire($profile);
        $owner = ++self::$nextOwner;
        $input = (static function (iterable $requests): \Generator {
            yield from $requests;
        })($requests);

        /** @var array<int, Transfer> $inflight spl_object_id(handle) => transfer */
        $inflight = [];
        $limit = $this->maxWindow($profile);
        $window = $limit;
        $pilotPending = $profile->httpVersion === HttpVersion::Http2 && $profile->requireHttp2 && $pool->negotiated === null;
        $stopReason = null;

        try {
            while (true) {
                // 1. Fill the window, pulling lazily from the input.
                while ($input->valid() && count($inflight) < ($pilotPending ? 1 : $window)) {
                    $request = $input->current();
                    $input->next();
                    if (! $request instanceof Request) {
                        continue;
                    }
                    if ($stopReason !== null) {
                        yield $request->id => Exchange::failed($request, CurlErrorClassifier::notDispatched($stopReason));

                        continue;
                    }
                    $transfer = $this->dispatch($pool, $owner, $request, $profile);
                    if ($transfer instanceof Exchange) {
                        yield $request->id => $transfer;

                        continue;
                    }
                    $inflight[spl_object_id($transfer->handle)] = $transfer;
                }

                if ($inflight === []) {
                    if ($input->valid()) {
                        continue;
                    }
                    break;
                }

                // 2. Drive the multi handle and collect what finished for us.
                $done = $pool->takeCompleted($owner);
                if ($done === []) {
                    $pool->perform(0.5);
                    $done = $pool->takeCompleted($owner);
                }

                foreach ($done as $completed) {
                    $id = spl_object_id($completed['handle']);
                    $transfer = $inflight[$id] ?? null;
                    if ($transfer === null) {
                        continue;
                    }
                    unset($inflight[$id]);
                    $exchange = $this->finish($transfer, $completed['result'], $profile, $pool);
                    $pool->remove($transfer->handle);
                    $pool->recycle($transfer->handle);
                    $pilotPending = false;

                    $failure = $exchange->failure;
                    if ($failure !== null && $failure->errno === CurlErrorClassifier::SEND_FAIL_REWIND) {
                        // The peer refused/abandoned the stream: back off (multiplicative decrease).
                        $window = max(1, intdiv($window, 2));
                    } elseif ($failure === null && $window < $limit) {
                        $window++; // additive increase
                    }
                    if ($failure !== null && $failure->errno === 0 && str_starts_with($failure->message, self::HTTP2_REQUIRED)) {
                        $stopReason = 'Not sent: the peer did not negotiate HTTP/2 on this pool, which the profile requires.';
                    }

                    yield $transfer->request->id => $exchange;
                }
            }
        } finally { fwrite(STDERR, "FINALLY inflight=".count($inflight)."\n");
            // Consumer stopped early (or the input threw): abort what is still running, keep the pool healthy.
            foreach ($inflight as $transfer) {
                $pool->remove($transfer->handle);
                curl_close($transfer->handle);
            }
            if ($inflight !== [] && ! $pool->isClosed()) {
                // libcurl only queues the RST_STREAM(CANCEL) frames; flush them now so the peer stops working on them.
                $pool->perform(0.0);
            }
            if ($stopReason !== null) {
                $this->pools->close($profile->poolKey);
            }
            $this->pools->release($pool);
        }
    }

    private function maxWindow(ConnectionProfile $profile): int
    {
        $window = max(1, $profile->window);

        // HTTP/1.1 has one request per connection at a time: more in flight would only queue inside libcurl
        // with the total timeout already running.
        return $profile->httpVersion === HttpVersion::Http11 ? min($window, max(1, $profile->maxConnections)) : $window;
    }

    private function dispatch(CurlPool $pool, int $owner, Request $request, ConnectionProfile $profile): Transfer|Exchange
    {
        $headers = ['expect:'];
        $hasContentType = false;
        foreach ($request->headers as $name => $value) {
            $name = strtolower((string) $name);
            if (preg_match('/^[!#$%&\'*+.^_`|~0-9a-z-]+$/', $name) !== 1 || preg_match('/[\r\n\0]/', $value) === 1) {
                return Exchange::failed($request, CurlErrorClassifier::notDispatched("Not sent: invalid header '{$name}'."));
            }
            $hasContentType = $hasContentType || $name === 'content-type';
            $headers[] = $name.': '.$value;
        }
        if (preg_match('/^[A-Z]+$/', $request->method) !== 1) {
            return Exchange::failed($request, CurlErrorClassifier::notDispatched("Not sent: invalid method '{$request->method}'."));
        }

        try {
            $handle = $pool->takeHandle();
        } catch (\RuntimeException $e) {
            return Exchange::failed($request, CurlErrorClassifier::notDispatched('Not sent: '.$e->getMessage()));
        }

        $transfer = new Transfer($request, $handle, hrtime(true) / 1e6);
        $this->configure($handle, $transfer, $profile, $headers, $hasContentType);

        $code = $pool->add($handle, $owner);
        if ($code !== CURLM_OK) {
            $pool->recycle($handle);

            return Exchange::failed($request, CurlErrorClassifier::notDispatched('Not sent: curl_multi_add_handle failed: '.(curl_multi_strerror($code) ?? (string) $code)));
        }

        return $transfer;
    }

    /**
     * @param  list<string>  $headers
     */
    private function configure(\CurlHandle $handle, Transfer $transfer, ConnectionProfile $profile, array $headers, bool $hasContentType): void
    {
        $request = $transfer->request;
        $http2 = $profile->httpVersion === HttpVersion::Http2;

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTP_VERSION => $http2 ? CURL_HTTP_VERSION_2TLS : CURL_HTTP_VERSION_1_1,
            CURLOPT_PIPEWAIT => $http2,
            // TLS verification is always on. There is deliberately no option to turn it off.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, $profile->connectTimeoutMs),
            CURLOPT_TIMEOUT_MS => max(1, $request->timeoutMs ?? $profile->timeoutMs),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => self::TCP_KEEPIDLE_SECONDS,
            CURLOPT_TCP_KEEPINTVL => self::TCP_KEEPINTVL_SECONDS,
            CURLOPT_TCP_NODELAY => true,
            // libcurl's default is 118 s; align connection reuse with the pool's own idle expiry.
            CURLOPT_MAXAGE_CONN => max(1, $profile->maxIdleSeconds),
            CURLOPT_FORBID_REUSE => false,
            CURLOPT_FRESH_CONNECT => false,
            CURLOPT_FOLLOWLOCATION => false,
            // An empty string disables the http(s)_proxy environment variables: the proxy is explicit or none.
            CURLOPT_PROXY => $profile->proxy ?? '',
            CURLOPT_SUPPRESS_CONNECT_HEADERS => true,
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $ch, string $line) use ($transfer): int {
                $transfer->responseBytes += strlen($line);

                return $transfer->headers->line($line);
            },
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $ch, string $chunk) use ($transfer): int {
                $transfer->responseBytes += strlen($chunk);
                if (strlen($transfer->responseBody) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $transfer->bodyTooLarge = true;

                    return 0; // aborts the transfer with CURLE_WRITE_ERROR
                }
                $transfer->responseBody .= $chunk;

                return strlen($chunk);
            },
        ];

        if ($profile->caBundlePath !== null) {
            $options[CURLOPT_CAINFO] = $profile->caBundlePath;
        }
        if ($profile->clientCertificatePem !== null && $profile->clientKeyPem !== null) {
            $options[CURLOPT_SSLCERT_BLOB] = $profile->clientCertificatePem;
            $options[CURLOPT_SSLCERTTYPE] = 'PEM';
            $options[CURLOPT_SSLKEY_BLOB] = $profile->clientKeyPem;
            $options[CURLOPT_SSLKEYTYPE] = 'PEM';
            if ($profile->clientKeyPassphrase !== null) {
                $options[CURLOPT_KEYPASSWD] = $profile->clientKeyPassphrase;
            }
        }

        $method = $request->method;
        if ($request->body !== '') {
            // Streamed body, no seek callback: libcurl cannot silently re-send it (see class docblock).
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            $options[CURLOPT_INFILESIZE] = strlen($request->body);
            $options[CURLOPT_READFUNCTION] = static function (\CurlHandle $ch, mixed $fd, int $length) use ($transfer): string {
                $chunk = (string) substr($transfer->request->body, $transfer->bodyOffset, $length);
                $transfer->bodyOffset += strlen($chunk);
                $transfer->bodyRead += strlen($chunk);

                return $chunk;
            };
        } elseif ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } elseif ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                $options[CURLOPT_POSTFIELDS] = '';
                if (! $hasContentType) {
                    $headers[] = 'content-type:'; // drop libcurl's form-urlencoded default
                }
            }
        }
        $options[CURLOPT_HTTPHEADER] = $headers;

        curl_setopt_array($handle, $options);
    }

    private function finish(Transfer $transfer, int $result, ConnectionProfile $profile, CurlPool $pool): Exchange
    {
        $handle = $transfer->handle;
        $request = $transfer->request;
        $duration = hrtime(true) / 1e6 - $transfer->startedAt;

        if ($result === CURLE_OK) {
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $version = self::versionString((int) curl_getinfo($handle, CURLINFO_HTTP_VERSION));

            if ($profile->httpVersion === HttpVersion::Http2 && $profile->requireHttp2 && $version !== '2') {
                return Exchange::failed($request, new TransportFailure(
                    TransportEvidence::Unknown,
                    0,
                    self::HTTP2_REQUIRED." but the peer negotiated HTTP/{$version}; the request was sent and answered with status {$status}, the response was discarded.",
                    true,
                ), $duration);
            }
            if ($status < 100) {
                return Exchange::failed($request, CurlErrorClassifier::classify(0, 'no HTTP status received', 1, 1, $transfer->bodyRead, $transfer->responseBytes), $duration);
            }
            $pool->negotiated = $version;

            return Exchange::responded($request, new Response($status, $transfer->headers->headers(), $transfer->responseBody, $version), $duration);
        }

        $message = curl_error($handle);
        if ($transfer->bodyTooLarge) {
            $message = 'response body larger than '.self::MAX_RESPONSE_BYTES.' bytes, aborted';
        }

        return Exchange::failed($request, CurlErrorClassifier::classify(
            $result,
            $message,
            (int) curl_getinfo($handle, CURLINFO_REQUEST_SIZE),
            (int) curl_getinfo($handle, CURLINFO_PRETRANSFER_TIME_T),
            $transfer->bodyRead,
            $transfer->responseBytes,
        ), $duration);
    }

    private static function versionString(int $curlVersion): string
    {
        return match ($curlVersion) {
            CURL_HTTP_VERSION_1_0 => '1.0',
            CURL_HTTP_VERSION_1_1 => '1.1',
            CURL_HTTP_VERSION_2_0 => '2',
            30 => '3', // CURL_HTTP_VERSION_3
            default => (string) $curlVersion,
        };
    }
}
