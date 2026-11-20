<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Transport\Curl;

use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;

/**
 * Turns a failed libcurl transfer into a TransportFailure with honest evidence.
 *
 * NotSent requires ALL of the following (see docs/transport.md, "Proof of NotSent"):
 *  1. the error code belongs to the resolve / connect / TLS-handshake / proxy-handshake family, i.e. a phase
 *     that precedes the request in libcurl's state machine;
 *  2. CURLINFO_REQUEST_SIZE is 0. libcurl adds to it every byte of request it hands to the connection filters,
 *     and it is cumulative across libcurl's internal retries (measured: 131 -> 262 -> 786), so 0 means no attempt
 *     of this transfer ever produced a request byte;
 *  3. CURLINFO_PRETRANSFER_TIME_T is 0: no attempt ever reached the DO phase where the request is generated.
 *     The timer is not reset by an internal retry;
 *  4. our own read callback was never asked for body bytes and no response header byte arrived.
 *
 * A connect error alone is NOT enough: libcurl retries some failures on a fresh connection by itself and reports
 * the error of the last attempt, e.g. errno 7 "Couldn't connect" with REQUEST_SIZE 131 from an earlier attempt.
 * Everything that is not proven is Unknown. A timeout is NotSent only when conditions 2-4 hold, which happens when
 * it expired while connecting or while waiting for a connection slot.
 */
final class CurlErrorClassifier
{
    /** libcurl error codes (some have no PHP constant, e.g. CURLE_HTTP2_STREAM = 92). */
    public const UNSUPPORTED_PROTOCOL = 1;

    public const URL_MALFORMAT = 3;

    public const COULDNT_RESOLVE_PROXY = 5;

    public const COULDNT_RESOLVE_HOST = 6;

    public const COULDNT_CONNECT = 7;

    public const HTTP2 = 16;

    public const PARTIAL_FILE = 18;

    public const WRITE_ERROR = 23;

    public const OPERATION_TIMEDOUT = 28;

    public const SSL_CONNECT_ERROR = 35;

    public const ABORTED_BY_CALLBACK = 42;

    public const SSL_ENGINE_NOTFOUND = 53;

    public const SSL_ENGINE_SETFAILED = 54;

    public const SEND_ERROR = 55;

    public const RECV_ERROR = 56;

    public const SSL_CERTPROBLEM = 58;

    public const SSL_CIPHER = 59;

    public const PEER_FAILED_VERIFICATION = 60;

    public const USE_SSL_FAILED = 64;

    public const SEND_FAIL_REWIND = 65;

    public const SSL_ENGINE_INITFAILED = 66;

    public const SSL_CACERT_BADFILE = 77;

    public const SSL_CRL_BADFILE = 82;

    public const SSL_ISSUER_ERROR = 83;

    public const SSL_PINNEDPUBKEYNOTMATCH = 90;

    public const SSL_INVALIDCERTSTATUS = 91;

    public const HTTP2_STREAM = 92;

    public const PROXY = 97;

    public const SSL_CLIENTCERT = 98;

    /** Errors raised by phases that precede the request. */
    private const PRE_REQUEST = [
        self::UNSUPPORTED_PROTOCOL, self::URL_MALFORMAT, self::COULDNT_RESOLVE_PROXY, self::COULDNT_RESOLVE_HOST,
        self::COULDNT_CONNECT, self::OPERATION_TIMEDOUT, self::SSL_CONNECT_ERROR, self::SSL_ENGINE_NOTFOUND,
        self::SSL_ENGINE_SETFAILED, self::SSL_CERTPROBLEM, self::SSL_CIPHER, self::PEER_FAILED_VERIFICATION,
        self::USE_SSL_FAILED, self::SSL_ENGINE_INITFAILED, self::SSL_CACERT_BADFILE, self::SSL_CRL_BADFILE,
        self::SSL_ISSUER_ERROR, self::SSL_PINNEDPUBKEYNOTMATCH, self::SSL_INVALIDCERTSTATUS, self::PROXY,
        self::SSL_CLIENTCERT,
    ];

    /**
     * @param  int  $requestSize  CURLINFO_REQUEST_SIZE
     * @param  int  $pretransferUs  CURLINFO_PRETRANSFER_TIME_T
     * @param  int  $bodyBytesRead  bytes our read callback handed to libcurl (all attempts)
     * @param  int  $responseBytes  header + body bytes received (all attempts)
     */
    public static function classify(
        int $errno,
        string $message,
        int $requestSize,
        int $pretransferUs,
        int $bodyBytesRead,
        int $responseBytes,
    ): TransportFailure {
        $nothingLeft = $requestSize === 0 && $pretransferUs === 0 && $bodyBytesRead === 0 && $responseBytes === 0;

        if ($nothingLeft && in_array($errno, self::PRE_REQUEST, true)) {
            return new TransportFailure(TransportEvidence::NotSent, $errno, self::describe($errno, $message), false);
        }

        return new TransportFailure(TransportEvidence::Unknown, $errno, self::describe($errno, $message), ! $nothingLeft);
    }

    /** A request we refused to hand to libcurl at all (invalid header, add_handle failure, aborted batch). */
    public static function notDispatched(string $message, int $errno = 0): TransportFailure
    {
        return new TransportFailure(TransportEvidence::NotSent, $errno, $message, false);
    }

    private static function describe(int $errno, string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            $message = curl_strerror($errno) ?? 'unknown error';
        }
        if ($errno === self::SEND_FAIL_REWIND) {
            // See docs/transport.md: this is libcurl trying to silently re-send the request after the connection
            // or the stream failed (REFUSED_STREAM, GOAWAY above last-stream-id, or a reused connection that died
            // before any response byte). We block that re-send on purpose to avoid duplicates.
            $message .= ' (libcurl attempted an automatic re-send, blocked to avoid duplicates)';
        }

        return "cURL error {$errno}: {$message}";
    }
}
