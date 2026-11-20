<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Auth\DerSignature;
use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;

function derOfRs(string $r, string $s): string
{
    $int = static function (string $v): string {
        $v = ltrim($v, "\x00");
        if ($v === '' || ord($v[0]) >= 0x80) {
            $v = "\x00".$v;
        }

        return "\x02".chr(strlen($v)).$v;
    };
    $body = $int($r).$int($s);

    return "\x30".chr(strlen($body)).$body;
}

it('converts the shapes of R and S that break naive implementations', function (string $r, string $s) {
    $jose = DerSignature::toJose(derOfRs($r, $s));

    expect(strlen($jose))->toBe(64)
        ->and(substr($jose, 0, 32))->toBe(str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT))
        ->and(substr($jose, 32))->toBe(str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT))
        ->and(DerSignature::fromJose($jose))->toBe(derOfRs($r, $s));
})->with([
    'plain' => [str_repeat("\x11", 32), str_repeat("\x22", 32)],
    'high bit set (DER sign padding)' => [str_repeat("\xFF", 32), str_repeat("\x80", 32)],
    'short R' => ["\x01".str_repeat("\x7F", 20), str_repeat("\x33", 32)],
    'short S' => [str_repeat("\x33", 32), "\x05"],
    'leading zero byte in R' => ["\x00\x7F".str_repeat("\x44", 30), str_repeat("\x55", 32)],
    'leading zero then high bit in S' => [str_repeat("\x55", 32), "\x00\x81".str_repeat("\x01", 30)],
    'tiny both' => ["\x01", "\x02"],
]);

it('round-trips random OpenSSL signatures of every DER size', function () {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $lengths = [];
    for ($i = 0; $i < 300; $i++) {
        openssl_sign(random_bytes(40), $sig, $key, OPENSSL_ALGO_SHA256);
        $lengths[strlen($sig)] = true;
        $jose = DerSignature::toJose($sig);
        expect(strlen($jose))->toBe(64)->and(DerSignature::fromJose($jose))->toBe($sig);
    }
    expect(count($lengths))->toBeGreaterThan(1);
});

it('round-trips random JOSE values including zero-heavy ones', function () {
    for ($i = 0; $i < 300; $i++) {
        $jose = random_bytes(64);
        if ($i % 3 === 0) {
            $jose[0] = "\x00";
        }
        if ($i % 5 === 0) {
            $jose[32] = "\x00";
            $jose[33] = "\x00";
        }
        expect(DerSignature::toJose(DerSignature::fromJose($jose)))->toBe($jose);
    }
});

it('rejects malformed DER', function (string $input) {
    DerSignature::toJose($input);
})->with([
    'empty' => [''],
    'not a sequence' => [str_repeat("\x31", 10)],
    'bad sequence length' => ["\x30\x20\x02\x01\x01\x02\x01\x01"],
    'trailing bytes' => [derOfRs("\x01", "\x02")."\x00"],
    'component too large' => [derOfRs(str_repeat("\x01", 33), "\x02")],
    'integer tag missing' => ["\x30\x06\x03\x01\x01\x02\x01\x01"],
])->throws(AuthenticationException::class);

it('rejects an odd-length JOSE signature', function () {
    DerSignature::fromJose('abc');
})->throws(AuthenticationException::class);
