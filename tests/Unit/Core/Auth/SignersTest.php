<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Padosoft\MobilePush\Core\Auth\DerSignature;
use Padosoft\MobilePush\Core\Auth\JwtEncoder;
use Padosoft\MobilePush\Core\Auth\OpenSslEs256Signer;
use Padosoft\MobilePush\Core\Auth\OpenSslRs256Signer;
use Padosoft\MobilePush\Core\Auth\PrivateKey;
use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;
use Padosoft\MobilePush\Tests\Scenario\Auth\TestKeys;

function b64u(string $s): string
{
    return JwtEncoder::base64UrlDecode($s);
}

function derLen(int $n): string
{
    return $n < 0x80 ? chr($n) : ($n < 0x100 ? "\x81".chr($n) : "\x82".chr($n >> 8).chr($n & 255));
}

function derInt(string $bytes): string
{
    $bytes = ltrim($bytes, "\x00");
    if (ord($bytes[0]) >= 0x80) {
        $bytes = "\x00".$bytes;
    }

    return "\x02".derLen(strlen($bytes)).$bytes;
}

function pemOf(string $der): string
{
    return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
}

it('verifies the RFC 7515 A.3 ES256 example signature through our DER conversion', function () {
    $x = b64u('f83OJ3D2xF1Bg8vub9tLe1gHMzV76e8Tus9uPHvRVEU');
    $y = b64u('x_FEzRu9m36HLN_tue659LNpXW6pCyStikYjKIWI5a0');
    $pem = pemOf(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')."\x04".$x.$y);

    $input = 'eyJhbGciOiJFUzI1NiJ9.eyJpc3MiOiJqb2UiLA0KICJleHAiOjEzMDA4MTkzODAsDQogImh0dHA6Ly9leGFtcGxlLmNvbS9pc19yb290Ijp0cnVlfQ';
    $jose = b64u('DtEhU3ljbEg8L38VWAfUAqOyKAM6-Xx-F4GawxaepmXFCgfTjDxw5djxLa8ISlSApmWQxfKTUJqPP3-Kg6NU1Q');

    expect(strlen($jose))->toBe(64)
        ->and(openssl_verify($input, DerSignature::fromJose($jose), $pem, OPENSSL_ALGO_SHA256))->toBe(1);
});

it('verifies the RFC 7515 A.2 RS256 example signature with the RFC public key', function () {
    $n = b64u('ofgWCuLjybRlzo0tZWJjNiuSfb4p4fAkd_wWJcyQoTbji9k0l8W26mPddxHmfHQp-Vaw-4qPCJrcS2mJPMEzP1Pt0Bm4d4QlL-yRT-SFd2lZS-pCgNMsD1W_YpRPEwOWvG6b32690r2jZ47soMZo9wGzjb_7OMg0LOL-bSf63kpaSHSXndS5z5rexMdbBYUsLA9e-KXBdQOS-UTo7WTBEMa2R2CapHg665xsmtdVMTBQY4uDZlxvb3qCo5ZwKh9kG4LT6_I5IhlJH7aGhyxXFvUK-DWNmoudF8NAco9_h9iaGNj8q2ethFkMLs91kzk2PAcDTW9gb54h4FRWyuXpoQ');
    $rsaKey = "\x30".derLen(strlen(derInt($n).derInt("\x01\x00\x01"))).derInt($n).derInt("\x01\x00\x01");
    $algo = hex2bin('300d06092a864886f70d0101010500');
    $bitString = "\x03".derLen(strlen($rsaKey) + 1)."\x00".$rsaKey;
    $inner = $algo.$bitString;
    $pem = pemOf("\x30".derLen(strlen($inner)).$inner);

    $input = 'eyJhbGciOiJSUzI1NiJ9.eyJpc3MiOiJqb2UiLA0KICJleHAiOjEzMDA4MTkzODAsDQogImh0dHA6Ly9leGFtcGxlLmNvbS9pc19yb290Ijp0cnVlfQ';
    $signature = b64u('cC4hiUPoj9Eetdgtv3hF80EGrhuB__dzERat0XF9g2VtQgr9PJbu3XOiZj5RZmh7AAuHIm4Bh-0Qc_lF5YKt_O8W2Fp5jujGbds9uJdbF9CUAr7t1dnZcAcQjbKBYNX4BAynRFdiuB--f_nZLgrnbyTyWzO75vRK5h6xBArLIARNPvkSjtQBMHlb1L07Qe7K0GarZRmB_eSN9383LcOLn6_dO--xi12jzDwusC-eOkHWEsqtFZESc6BfI7noOPqvhJ1phCnvWh6IeYI2w9QOYEUipUTI8np6LbgGY9Fs98rqVt5AXLIhWkWywlVmtVrBp0igcN_IoypGlUPQGe77Rw');

    expect(openssl_verify($input, $signature, $pem, OPENSSL_ALGO_SHA256))->toBe(1);
});

it('RS256 is deterministic and matches a direct OpenSSL signature', function () {
    $k = TestKeys::rsa();
    $signer = new OpenSslRs256Signer(PrivateKey::fromPem($k['pem']), 'kid-1');

    $a = $signer->sign('header.claims');
    openssl_sign('header.claims', $direct, $k['pem'], OPENSSL_ALGO_SHA256);

    expect($a)->toBe($signer->sign('header.claims'))->toBe($direct)
        ->and($signer->algorithm())->toBe('RS256')->and($signer->keyId())->toBe('kid-1');
});

it('ES256 signatures are 64 bytes and verify, many times over', function () {
    $k = TestKeys::ec();
    $signer = new OpenSslEs256Signer(PrivateKey::fromPem($k['pem']), 'ABC123DEFG');
    for ($i = 0; $i < 100; $i++) {
        $input = 'in'.$i;
        $sig = $signer->sign($input);
        expect(strlen($sig))->toBe(64)
            ->and(openssl_verify($input, DerSignature::fromJose($sig), $k['public'], OPENSSL_ALGO_SHA256))->toBe(1);
    }
});

it('interoperates with firebase/php-jwt in both directions', function () {
    $ec = TestKeys::ec();
    $rsa = TestKeys::rsa();
    $encoder = new JwtEncoder;
    $claims = ['iss' => 'team', 'iat' => time(), 'aud' => 'https://x.test/a/b'];

    foreach ([[new OpenSslEs256Signer(PrivateKey::fromPem($ec['pem']), 'K1'), $ec, 'ES256'], [new OpenSslRs256Signer(PrivateKey::fromPem($rsa['pem']), 'K2'), $rsa, 'RS256']] as [$signer, $k, $alg]) {
        // ours -> theirs
        $jwt = $encoder->encode(['typ' => 'JWT'], $claims, $signer);
        $decoded = JWT::decode($jwt, new Key($k['public'], $alg));
        expect($decoded->iss)->toBe('team');

        $header = json_decode(b64u(explode('.', $jwt)[0]), true);
        expect($header)->toBe(['typ' => 'JWT', 'alg' => $alg, 'kid' => $signer->keyId()])
            ->and(str_contains($jwt, '='))->toBeFalse()
            ->and(str_contains(b64u(explode('.', $jwt)[1]), '\/'))->toBeFalse();

        // theirs -> ours (verify with our DER path / openssl)
        $theirs = JWT::encode($claims, $k['pem'], $alg, 'K');
        [$h, $c, $s] = explode('.', $theirs);
        $sig = b64u($s);
        $der = $alg === 'ES256' ? DerSignature::fromJose($sig) : $sig;
        expect(openssl_verify($h.'.'.$c, $der, $k['public'], OPENSSL_ALGO_SHA256))->toBe(1);
    }
});

it('rejects keys that do not fit the algorithm without leaking material', function (Closure $make) {
    try {
        $make();
        throw new Exception('expected failure');
    } catch (AuthenticationException $e) {
        expect($e->getMessage())->not->toContain('PRIVATE KEY')->not->toContain('MII');
    }
})->with([
    'ES256 with P-384' => [fn () => new OpenSslEs256Signer(PrivateKey::fromPem(TestKeys::ec('secp384r1')['pem']))],
    'ES256 with RSA' => [fn () => new OpenSslEs256Signer(PrivateKey::fromPem(TestKeys::rsa()['pem']))],
    'RS256 with EC' => [fn () => new OpenSslRs256Signer(PrivateKey::fromPem(TestKeys::ec()['pem']))],
    'RS256 with RSA 1024' => [fn () => new OpenSslRs256Signer(PrivateKey::fromPem(TestKeys::rsa(1024)['pem']))],
    'garbage PEM' => [fn () => PrivateKey::fromPem("-----BEGIN PRIVATE KEY-----\nnot-a-key\n-----END PRIVATE KEY-----")],
    'empty' => [fn () => PrivateKey::fromPem('  ')],
]);

it('normalises literal \n, CRLF, single-line and missing trailing newline PEMs', function () {
    $pem = TestKeys::ec()['pem'];
    $variants = [
        'literal' => str_replace("\n", '\n', trim($pem)),
        'crlf' => str_replace("\n", "\r\n", $pem),
        'no trailing newline' => rtrim($pem),
        'single line' => preg_replace('/\s*\n\s*/', ' ', trim($pem)),
    ];
    foreach ($variants as $name => $variant) {
        $key = PrivateKey::fromPem($variant);
        expect($key->curve())->toBe('prime256v1', $name);
    }
});

it('supports passphrase-protected keys and never dumps or serializes', function () {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($key, $pem, 'pw-for-test');

    $parsed = PrivateKey::fromPem($pem, 'pw-for-test');
    expect($parsed->isEc())->toBeTrue()
        ->and(var_export($parsed, true))->not->toContain('PRIVATE')
        ->and(fn () => serialize($parsed))->toThrow(LogicException::class);
    expect(fn () => PrivateKey::fromPem($pem, 'wrong'))->toThrow(AuthenticationException::class);
});
