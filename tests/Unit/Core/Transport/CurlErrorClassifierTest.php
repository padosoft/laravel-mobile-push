<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Transport\Curl\CurlErrorClassifier as C;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;

/*
 * NotSent is a promise that the request can be re-sent without risk of a duplicate.
 * These cases pin exactly when the classifier is allowed to make it.
 */

it('classifies a pre-request failure with no trace of a request as NotSent', function (int $errno): void {
    $failure = C::classify($errno, 'boom', requestSize: 0, pretransferUs: 0, bodyBytesRead: 0, responseBytes: 0);

    expect($failure->evidence)->toBe(TransportEvidence::NotSent)
        ->and($failure->requestWritten)->toBeFalse()
        ->and($failure->errno)->toBe($errno)
        ->and($failure->message)->toBe("cURL error {$errno}: boom");
})->with([
    'unsupported protocol (http://)' => C::UNSUPPORTED_PROTOCOL,
    'malformed url' => C::URL_MALFORMAT,
    'proxy not resolved' => C::COULDNT_RESOLVE_PROXY,
    'host not resolved' => C::COULDNT_RESOLVE_HOST,
    'connection refused' => C::COULDNT_CONNECT,
    'timeout while connecting' => C::OPERATION_TIMEDOUT,
    'TLS handshake failed' => C::SSL_CONNECT_ERROR,
    'untrusted server certificate' => C::PEER_FAILED_VERIFICATION,
    'bad client certificate' => C::SSL_CERTPROBLEM,
    'unreadable CA bundle' => C::SSL_CACERT_BADFILE,
    'pinned key mismatch' => C::SSL_PINNEDPUBKEYNOTMATCH,
    'proxy handshake' => C::PROXY,
]);

it('never classifies a connect-family error as NotSent once any trace of a request exists', function (int $requestSize, int $pretransfer, int $bodyRead, int $responseBytes): void {
    // e.g. libcurl retried a request on a fresh connection by itself and the reconnect failed:
    // measured errno 7 with REQUEST_SIZE 131 from the earlier attempt.
    $failure = C::classify(C::COULDNT_CONNECT, 'Recv failure', $requestSize, $pretransfer, $bodyRead, $responseBytes);

    expect($failure->evidence)->toBe(TransportEvidence::Unknown)->and($failure->requestWritten)->toBeTrue();
})->with([
    'request bytes counted by libcurl' => [131, 0, 0, 0],
    'reached the DO phase' => [0, 1, 0, 0],
    'body handed to libcurl' => [0, 0, 7, 0],
    'response bytes arrived' => [0, 0, 0, 12],
]);

it('classifies failures after the request phase as Unknown, even with clean counters', function (int $errno): void {
    $failure = C::classify($errno, '', 0, 0, 0, 0);

    expect($failure->evidence)->toBe(TransportEvidence::Unknown);
})->with([
    'send error' => C::SEND_ERROR,
    'recv error' => C::RECV_ERROR,
    'http2 framing' => C::HTTP2,
    'http2 stream reset' => C::HTTP2_STREAM,
    'partial body' => C::PARTIAL_FILE,
    'blocked automatic re-send' => C::SEND_FAIL_REWIND,
    'write callback abort' => C::WRITE_ERROR,
    'unknown code' => 9999,
]);

it('keeps a timeout after the request was written Unknown', function (): void {
    expect(C::classify(C::OPERATION_TIMEDOUT, 'Operation timed out', 75, 12, 7, 0)->evidence)->toBe(TransportEvidence::Unknown);
});

it('explains a blocked automatic re-send in the message', function (): void {
    expect(C::classify(C::SEND_FAIL_REWIND, "necessary data rewind wasn't possible", 75, 10, 7, 0)->message)
        ->toContain('automatic re-send, blocked to avoid duplicates');
});

it('falls back to libcurl\'s description when the error buffer is empty', function (): void {
    // The wording belongs to the installed libcurl ("Couldn't connect to server" up to 8.5, "Could not connect to
    // server" in later releases): the contract is that its description is used, whatever it says.
    expect(C::classify(C::COULDNT_CONNECT, '  ', 0, 0, 0, 0)->message)
        ->toBe('cURL error 7: '.curl_strerror(C::COULDNT_CONNECT))
        ->toContain('connect to server');
});

it('marks locally refused requests as NotSent and not written', function (): void {
    $failure = C::notDispatched('Not sent: invalid header.');

    expect($failure->evidence)->toBe(TransportEvidence::NotSent)
        ->and($failure->requestWritten)->toBeFalse()
        ->and($failure->errno)->toBe(0);
});
