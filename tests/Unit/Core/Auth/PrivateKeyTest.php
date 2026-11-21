<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Auth\PrivateKey;
use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;
use Padosoft\MobilePush\Tests\Scenario\Auth\TestKeys;

/** @return array{0: int, 1: int, 2: int} tag, offset of the value, length of the value */
function pkTlv(string $der, int $at): array
{
    $tag = ord($der[$at]);
    $len = ord($der[$at + 1]);
    $at += 2;
    if ($len & 0x80) {
        $bytes = $len & 0x7F;
        $len = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $len = ($len << 8) | ord($der[$at + $i]);
        }
        $at += $bytes;
    }

    return [$tag, $at, $len];
}

/** PKCS#8 "PRIVATE KEY" of an RSA key → the PKCS#1 "RSA PRIVATE KEY" it wraps. */
function pkPkcs1(string $pkcs8Pem): string
{
    $der = (string) base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pkcs8Pem) ?? '');
    [, $inside] = pkTlv($der, 0);              // PrivateKeyInfo SEQUENCE
    [, $vAt, $vLen] = pkTlv($der, $inside);    // version INTEGER
    [, $aAt, $aLen] = pkTlv($der, $vAt + $vLen); // AlgorithmIdentifier SEQUENCE
    [$tag, $kAt, $kLen] = pkTlv($der, $aAt + $aLen);
    expect($tag)->toBe(0x04);                  // privateKey OCTET STRING

    return "-----BEGIN RSA PRIVATE KEY-----\n".chunk_split(base64_encode(substr($der, $kAt, $kLen)), 64, "\n")."-----END RSA PRIVATE KEY-----\n";
}

it('brings every damaged spelling of a PEM back to the canonical 64-column form with one trailing newline', function (Closure $damage) {
    $pem = TestKeys::ec()['pem'];
    $canonical = rtrim($pem, "\n")."\n";
    $damaged = $damage($pem);

    expect(PrivateKey::normalizePem($damaged))->toBe($canonical)
        ->and(PrivateKey::fromPem($damaged)->curve())->toBe('prime256v1');
})->with([
    'canonical' => [fn (string $p) => $p],
    'literal \n' => [fn (string $p) => str_replace("\n", '\n', trim($p))],
    'literal \r\n' => [fn (string $p) => str_replace("\n", '\r\n', trim($p))],
    'literal \n at the end' => [fn (string $p) => trim($p).'\n'],
    'CRLF' => [fn (string $p) => str_replace("\n", "\r\n", $p)],
    'CR only' => [fn (string $p) => str_replace("\n", "\r", $p)],
    'no trailing newline' => [fn (string $p) => rtrim($p)],
    'several trailing newlines and spaces' => [fn (string $p) => $p."\n\n  "],
    'single line' => [fn (string $p) => (string) preg_replace('/\s*\n\s*/', ' ', trim($p))],
    'single line, no spaces' => [fn (string $p) => (string) preg_replace('/\s*\n\s*/', '', trim($p))],
    'single line inside spaces' => [fn (string $p) => '  '.preg_replace('/\s*\n\s*/', ' ', trim($p)).'  '],
]);

it('normalises a blank PEM to the empty string', function (string $blank) {
    expect(PrivateKey::normalizePem($blank))->toBe('')
        ->and(fn () => PrivateKey::fromPem($blank))->toThrow(AuthenticationException::class);
})->with(['empty' => [''], 'spaces' => ['   '], 'newlines' => ["\n\r\n\t"]]);

it('leaves a single-line PEM with headers (Proc-Type, DEK-Info) as it is', function () {
    $legacy = '-----BEGIN RSA PRIVATE KEY----- Proc-Type: 4,ENCRYPTED DEK-Info: AES-128-CBC,00112233445566778899AABBCCDDEEFF QUJD -----END RSA PRIVATE KEY-----';

    expect(PrivateKey::normalizePem($legacy))->toBe($legacy."\n");
});

it('parses PKCS#8 EC keys on each curve and PKCS#8 / PKCS#1 RSA keys, reporting type, curve and size', function () {
    $p256 = PrivateKey::fromPem(TestKeys::ec()['pem']);
    $p384 = PrivateKey::fromPem(TestKeys::ec('secp384r1')['pem']);
    $rsaPem = TestKeys::rsa()['pem'];
    $rsa8 = PrivateKey::fromPem($rsaPem);
    $rsa1 = PrivateKey::fromPem(pkPkcs1($rsaPem));

    expect([$p256->typeName(), $p256->isEc(), $p256->isRsa(), $p256->curve(), $p256->bits()])->toBe(['EC', true, false, 'prime256v1', 256])
        ->and([$p384->curve(), $p384->bits()])->toBe(['secp384r1', 384])
        ->and([$rsa8->typeName(), $rsa8->isRsa(), $rsa8->isEc(), $rsa8->curve(), $rsa8->bits()])->toBe(['RSA', true, false, null, 2048])
        ->and([$rsa1->typeName(), $rsa1->bits()])->toBe(['RSA', 2048]);
});

it('leaves no OpenSSL error text behind after refusing a key', function () {
    while (openssl_error_string() !== false) {
        // start from an empty queue
    }

    expect(fn () => PrivateKey::fromPem("-----BEGIN PRIVATE KEY-----\nbm90LWEta2V5\n-----END PRIVATE KEY-----"))
        ->toThrow(AuthenticationException::class, 'The private key could not be parsed (wrong format or passphrase).');
    expect(openssl_error_string())->toBeFalse();
});

it('never reveals the key in a dump, a serialization or an error', function () {
    $pem = TestKeys::ec()['pem'];
    $body = explode("\n", trim($pem))[1];
    $key = PrivateKey::fromPem($pem);

    expect($key->__debugInfo())->toBe(['key' => '[redacted]', 'type' => 'EC'])
        ->and(print_r($key, true))->not->toContain($body)
        ->and(var_export($key, true))->not->toContain($body)
        ->and(fn () => serialize($key))->toThrow(LogicException::class)
        ->and(fn () => unserialize(sprintf('O:%d:"%s":0:{}', strlen(PrivateKey::class), PrivateKey::class)))->toThrow(LogicException::class);

    $broken = str_replace($body, strrev($body), $pem);
    try {
        PrivateKey::fromPem($broken);
        throw new Exception('expected AuthenticationException');
    } catch (AuthenticationException $e) {
        expect($e->getMessage())->not->toContain(strrev($body))->not->toContain('BEGIN');
    }
});
