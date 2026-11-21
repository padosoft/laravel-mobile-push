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

it('rejects an empty JOSE signature', function () {
    DerSignature::fromJose('');
})->throws(AuthenticationException::class, 'odd or empty length');

it('encodes an all-zero R and S as the single byte INTEGER 0', function () {
    expect(bin2hex(DerSignature::fromJose(str_repeat("\x00", 64))))->toBe('3006020100020100');
});

it('encodes every INTEGER and SEQUENCE length at the short/long form boundaries', function (int $half, string $first, string $second, string $sequenceHeader, string $integerHeader) {
    $jose = str_repeat($first, $half).str_repeat($second, $half);
    $der = DerSignature::fromJose($jose);

    expect(bin2hex(substr($der, 0, strlen($sequenceHeader) / 2)))->toBe($sequenceHeader)
        ->and(bin2hex(substr($der, strlen($sequenceHeader) / 2, strlen($integerHeader) / 2)))->toBe($integerHeader)
        ->and(DerSignature::toJose($der, $half))->toBe($jose);
})->with([
    'sequence 126 bytes (short form)' => [61, "\x11", "\x11", '307e', '023d'],
    'sequence 127 bytes: last short form' => [61, "\xFF", "\x11", '307f', '023e'],
    'sequence 128 bytes: first long form' => [62, "\x11", "\x11", '308180', '023e'],
    'sequence 129 bytes' => [62, "\xFF", "\x11", '308181', '023f'],
    'integer 127 bytes (short form)' => [127, "\x11", "\x11", '30820102', '027f'],
    'integer 128 bytes: 127 + sign padding' => [127, "\xFF", "\xFF", '30820106', '028180'],
    'integer 128 bytes without padding' => [128, "\x11", "\x11", '30820106', '028180'],
    'integer 254 bytes' => [254, "\x11", "\x11", '30820202', '0281fe'],
    'integer 255 bytes: last 1-byte long form' => [255, "\x11", "\x11", '30820204', '0281ff'],
    'integer 256 bytes: first 2-byte long form' => [256, "\x11", "\x11", '30820208', '02820100'],
    'integer 256 bytes via sign padding' => [255, "\xFF", "\xFF", '30820208', '02820100'],
    'integer 258 bytes: both length bytes non zero' => [258, "\x11", "\x11", '3082020c', '02820102'],
]);

it('round-trips random P-521 signatures with a 66 byte component', function () {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp521r1']);
    $details = openssl_pkey_get_details($key);
    $seenLong = false;
    for ($i = 0; $i < 60; $i++) {
        $data = random_bytes(30);
        openssl_sign($data, $sig, $key, OPENSSL_ALGO_SHA512);
        $seenLong = $seenLong || ord($sig[1]) === 0x81;
        $jose = DerSignature::toJose($sig, 66);
        expect(strlen($jose))->toBe(132)
            ->and(openssl_verify($data, DerSignature::fromJose($jose), $details['key'], OPENSSL_ALGO_SHA512))->toBe(1);
    }
    expect($seenLong)->toBeTrue();
});

it('converts to JOSE and verifies with OpenSSL after converting back', function () {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $pub = openssl_pkey_get_details($key)['key'];
    for ($i = 0; $i < 50; $i++) {
        $data = random_bytes(25);
        openssl_sign($data, $sig, $key, OPENSSL_ALGO_SHA256);
        expect(openssl_verify($data, DerSignature::fromJose(DerSignature::toJose($sig)), $pub, OPENSSL_ALGO_SHA256))->toBe(1);
    }
});

/** A DER INTEGER/SEQUENCE TLV with a caller-chosen raw length encoding. */
function tlv(string $tag, string $lengthBytes, string $content): string
{
    return $tag.$lengthBytes.$content;
}

it('accepts long-form lengths, including padded two-byte ones', function (string $sequenceLength, int $rLength, string $rLengthBytes) {
    $r = tlv("\x02", $rLengthBytes, str_repeat("\x00", $rLength - 1)."\x07");
    $s = "\x02\x01\x09";
    $body = $r.$s;
    $der = "\x30".$sequenceLength.$body;

    expect(strlen($body))->toBeGreaterThanOrEqual(0x80)
        ->and(DerSignature::toJose($der))->toBe(str_repeat("\x00", 31)."\x07".str_repeat("\x00", 31)."\x09");
})->with(function () {
    $cases = [];
    foreach ([
        'integer length 0x80 as 0x81 0x80' => [128, "\x81\x80"],
        'integer length 0x90 as 0x82 0x00 0x90' => [144, "\x82\x00\x90"],
        'integer length 0x0104 as 0x82 0x01 0x04' => [260, "\x82\x01\x04"],
    ] as $label => [$len, $lb]) {
        $bodyLength = 1 + strlen($lb) + $len + 3;
        $seq = $bodyLength < 0x80 ? chr($bodyLength) : ($bodyLength < 0x100 ? "\x81".chr($bodyLength) : "\x82".chr($bodyLength >> 8).chr($bodyLength & 0xFF));
        $cases[$label] = [$seq, $len, $lb];
    }

    return $cases;
});

it('rejects DER at every length-encoding and structure boundary', function (string $hex, string $message) {
    expect(fn () => DerSignature::toJose(hex2bin(str_replace(' ', '', $hex))))
        ->toThrow(AuthenticationException::class, $message);
})->with([
    'sequence length 0x80 (indefinite form)' => ['30 80 02 01 01 02 01 01', 'bad length encoding'],
    'three length bytes' => ['30 83 00 00 06 02 01 01 02 01 01', 'bad length encoding'],
    'two length bytes announced, one present' => ['30 06 02 01 01 02 82 01', 'bad length encoding'],
    'long form whose length byte ends the input' => ['30 06 02 01 01 02 81 80', 'bad INTEGER length'],
    'long form non minimal (0x81 0x7f)' => ['30 81 7f'.str_repeat(' 00', 127), 'non-minimal'],
    'long form non minimal (0x81 0x00)' => ['30 81 00 02 01 01 02 01 01', 'non-minimal'],
    'sequence shorter than its content' => ['30 05 02 01 01 02 01 01', 'bad sequence length'],
    'sequence longer than the input' => ['30 07 02 01 01 02 01 01', 'bad sequence length'],
    'first INTEGER tag is wrong' => ['30 06 03 01 01 02 01 01', 'expected an INTEGER'],
    'second INTEGER tag is wrong' => ['30 06 02 01 01 03 01 01', 'expected an INTEGER'],
    'empty INTEGER R' => ['30 07 02 00 02 03 01 01 01', 'bad INTEGER length'],
    'empty INTEGER S' => ['30 07 02 03 01 01 01 02 00', 'bad INTEGER length'],
    'INTEGER running past the input' => ['30 06 02 01 01 02 05 01', 'bad INTEGER length'],
    'component of 33 significant bytes' => ['30 26 02 21'.str_repeat(' 01', 33).' 02 01 01', 'component too large'],
    'seven byte input' => ['30 05 02 01 01 02 01', 'not a DER sequence'],
]);

it('accepts the smallest valid DER signature of eight bytes', function () {
    expect(DerSignature::toJose(hex2bin('3006020101020102')))->toBe(str_repeat("\x00", 31)."\x01".str_repeat("\x00", 31)."\x02");
});

it('rejects a sequence whose total length is exactly its declared length plus trailing bytes', function () {
    expect(fn () => DerSignature::toJose(hex2bin('3006020101020102'.'00')))
        ->toThrow(AuthenticationException::class, 'bad sequence length');
});

it('rejects a third INTEGER inside the sequence as trailing bytes', function () {
    expect(fn () => DerSignature::toJose(hex2bin('3009020101020102020103')))
        ->toThrow(AuthenticationException::class, 'trailing bytes');
});

it('accepts a 0x7f short-form sequence length and a leading-zero INTEGER wider than the curve', function () {
    $r = "\x02\x7a".str_repeat("\x00", 0x79)."\x05";   // 122 bytes with zeros
    $s = "\x02\x01\x06";
    $body = $r.$s;
    expect(strlen($body))->toBe(0x7F)
        ->and(DerSignature::toJose("\x30\x7f".$body))->toBe(str_repeat("\x00", 31)."\x05".str_repeat("\x00", 31)."\x06");
});
