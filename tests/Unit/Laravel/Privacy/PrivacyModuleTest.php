<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Laravel\Privacy\PrivacyModule;

it('derives two different, stable keys from the application key by default', function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    $index = PrivacyModule::indexKeyRing($this->app)->key(1);
    $cipher = PrivacyModule::encryptionKeyRing($this->app)->key(1);

    expect(strlen($index))->toBe(32)
        ->and(strlen($cipher))->toBe(32)
        ->and($index)->not->toBe($cipher)
        ->and(PrivacyModule::indexKeyRing($this->app)->key(1))->toBe($index);
});

it('prefers configured keys, and rotation keeps old versions readable', function () {
    $old = random_bytes(32);
    $new = random_bytes(32);
    config(['mobile-push.privacy.encryption_keys' => [1 => base64_encode($old)]]);
    $envelope = (new TokenCipher(PrivacyModule::encryptionKeyRing($this->app)))->encrypt('secret-value', 'scope');

    config([
        'mobile-push.privacy.encryption_keys' => [1 => 'base64:'.base64_encode($old), 2 => base64_encode($new)],
        'mobile-push.privacy.encryption_key_current' => 2,
    ]);
    $ring = PrivacyModule::encryptionKeyRing($this->app);
    $cipher = new TokenCipher($ring);

    expect($ring->current)->toBe(2)
        ->and($ring->key(2))->toBe($new)
        ->and($cipher->decrypt($envelope, 'scope'))->toBe('secret-value')
        ->and($cipher->needsRotation($envelope))->toBeTrue();
});

it('rejects a short or malformed key without printing key material', function (string $bad) {
    config(['mobile-push.privacy.index_keys' => [1 => $bad]]);

    try {
        PrivacyModule::indexKeyRing($this->app);
        $this->fail('expected a ConfigurationException');
    } catch (ConfigurationException $e) {
        expect($e->getMessage())->not->toContain($bad)->and($e->getMessage())->toContain('random_bytes(32)');
    }
})->with([
    'too short' => fn () => base64_encode('tiny-key-material'),
    'not base64' => fn () => '%%%not-base64-at-all-but-long-enough-to-pass-a-length-check%%%',
]);

it('rejects a current version that is not in the map', function () {
    config(['mobile-push.privacy.index_keys' => [1 => base64_encode(random_bytes(32))], 'mobile-push.privacy.index_key_current' => 7]);

    expect(fn () => PrivacyModule::indexKeyRing($this->app))->toThrow(ConfigurationException::class);
});

it('binds TokenIndex, TokenCipher and Redactor as working singletons, with independent keys', function () {
    expect($this->app->make(TokenIndex::class))->toBe($this->app->make(TokenIndex::class))
        ->and($this->app->make(Redactor::class))->toBeInstanceOf(Redactor::class);

    $token = FcmToken::of('some-fcm-token-value');
    $index = $this->app->make(TokenIndex::class)->index($token, 'tenant-a');
    $cipher = $this->app->make(TokenCipher::class);

    expect($index)->toMatch('/^v1:[0-9a-f]{64}$/')
        ->and($cipher->decrypt($cipher->encrypt('x', 's'), 's'))->toBe('x');
});
