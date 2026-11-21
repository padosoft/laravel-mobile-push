<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Auth\JwtEncoder;
use Padosoft\MobilePush\Core\Auth\Signer;
use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;

function fixedSigner(?string $keyId): Signer
{
    return new class($keyId) implements Signer
    {
        public function __construct(private readonly ?string $kid) {}

        public function algorithm(): string
        {
            return 'ES256';
        }

        public function keyId(): ?string
        {
            return $this->kid;
        }

        public function sign(string $signingInput): string
        {
            return "\xFB\xFF\xFE";
        }
    };
}

it('produces the exact compact JWS bytes for a fixed input', function () {
    $jwt = (new JwtEncoder)->encode(['typ' => 'JWT'], ['iss' => 'a/b', 'n' => 'é'], fixedSigner('K1'));

    expect($jwt)->toBe('eyJ0eXAiOiJKV1QiLCJhbGciOiJFUzI1NiIsImtpZCI6IksxIn0.eyJpc3MiOiJhL2IiLCJuIjoiw6kifQ.-__-');
});

it('keeps a kid the caller put in the header over the signer key id', function () {
    $jwt = (new JwtEncoder)->encode(['kid' => 'mine'], [], fixedSigner('K1'));
    $header = json_decode(JwtEncoder::base64UrlDecode(explode('.', $jwt)[0]), true);

    expect($header)->toBe(['kid' => 'mine', 'alg' => 'ES256']);
});

it('adds no kid when neither the header nor the signer has one', function () {
    $jwt = (new JwtEncoder)->encode([], ['a' => 1], fixedSigner(null));
    $header = json_decode(JwtEncoder::base64UrlDecode(explode('.', $jwt)[0]), true);

    expect($header)->toBe(['alg' => 'ES256']);
});

it('always overrides alg with the signer algorithm', function () {
    $jwt = (new JwtEncoder)->encode(['alg' => 'none'], [], fixedSigner(null));

    expect(json_decode(JwtEncoder::base64UrlDecode(explode('.', $jwt)[0]), true))->toBe(['alg' => 'ES256']);
});

it('refuses claims that are not JSON-encodable', function () {
    (new JwtEncoder)->encode([], ['bad' => "\xB1\x31"], fixedSigner(null));
})->throws(AuthenticationException::class);

it('base64url-encodes without padding and with the URL alphabet', function (string $bytes, string $expected) {
    expect(JwtEncoder::base64UrlEncode($bytes))->toBe($expected)
        ->and(JwtEncoder::base64UrlDecode($expected))->toBe($bytes);
})->with([
    'empty' => ['', ''],
    'one byte (two pad chars)' => ["\xFB", '-w'],
    'two bytes (one pad char)' => ["\xFB\xFF", '-_8'],
    'three bytes' => ["\xFB\xFF\xFE", '-__-'],
]);

it('rejects characters outside the base64url alphabet instead of skipping them', function (string $text) {
    JwtEncoder::base64UrlDecode($text);
})->with(['punctuation' => ['ab!c'], 'dollar' => ['a$'], 'standard-alphabet padding in the middle' => ['ab=c']])
    ->throws(AuthenticationException::class);
