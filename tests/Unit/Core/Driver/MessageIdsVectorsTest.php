<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\MessageIds;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;

/*
 * Golden vectors computed outside this code base (Python's uuid/hashlib, see each comment), so a change to the
 * derivation of an effect id, which would give a resumed effect a different apns-id, cannot go unnoticed.
 */

it('derives the effect id from length-prefixed fields under the package namespace', function () {
    // hashlib.sha1(NAMESPACE.bytes + b''.join(struct.pack('>I', len(f)) + f for f in
    //   [b'binding-1', b'effect-1', b'apns_device_token', b'ab' * 32])), version 5, RFC variant
    expect(MessageIds::forEffect('binding-1', 'effect-1', ApnsDeviceToken::of(str_repeat('ab', 32))))
        ->toBe('59c0366c-77ac-5577-a41b-3048bb46de72');
});

it('sets the RFC variant bits without touching the low bits of the clock sequence', function () {
    // uuid.uuid5(uuid.NAMESPACE_DNS, 'example.com'): byte 8 is 0x96, even
    expect(MessageIds::v5('6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'example.com'))->toBe('cfbff0d1-9375-5685-968c-48ce8b15ae17');
});

it('accepts a namespace written in upper case and gives the same id', function () {
    expect(MessageIds::v5('6BA7B810-9DAD-11D1-80B4-00C04FD430C8', 'python.org'))->toBe('886313e1-3b8a-5372-9b90-0c9aee199e5d');
});

it('refuses a namespace that is not a canonical UUID', function (string $namespace) {
    expect(fn () => MessageIds::v5($namespace, 'x'))->toThrow(InvalidArgumentException::class);
})->with(['no dashes' => ['6ba7b8109dad11d180b400c04fd430c8'], 'not hex' => ['zba7b810-9dad-11d1-80b4-00c04fd430c8'], 'empty' => ['']]);
