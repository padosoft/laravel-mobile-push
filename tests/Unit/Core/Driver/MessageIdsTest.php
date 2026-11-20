<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\MessageIds;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsVoipToken;

it('computes RFC 9562 UUIDv5 values', function (string $namespace, string $name, string $expected) {
    expect(MessageIds::v5($namespace, $name))->toBe($expected);
})->with([
    // Python: uuid.uuid5(uuid.NAMESPACE_DNS, 'python.org')
    'DNS python.org' => ['6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'python.org', '886313e1-3b8a-5372-9b90-0c9aee199e5d'],
    // RFC 9562 appendix A.4: uuid5(NAMESPACE_DNS, 'www.example.com')
    'DNS www.example.com' => ['6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'www.example.com', '2ed6657d-e927-568b-95e1-2665a8aea6a2'],
]);

it('derives effect ids that are canonical, stable and separated by every input', function () {
    $token = ApnsDeviceToken::of(str_repeat('ab', 32));
    $id = MessageIds::forEffect('binding-1', 'effect-1', $token);

    expect(MessageIds::isCanonical($id))->toBeTrue()
        ->and($id[14])->toBe('5')
        ->and(MessageIds::forEffect('binding-1', 'effect-1', $token))->toBe($id)
        ->and(MessageIds::forEffect('binding-2', 'effect-1', $token))->not->toBe($id)
        ->and(MessageIds::forEffect('binding-1', 'effect-2', $token))->not->toBe($id)
        ->and(MessageIds::forEffect('binding-1', 'effect-1', ApnsDeviceToken::of(str_repeat('cd', 32))))->not->toBe($id)
        // same value, different kind of registration
        ->and(MessageIds::forEffect('binding-1', 'effect-1', ApnsVoipToken::of(str_repeat('ab', 32))))->not->toBe($id)
        // length prefixes: moving a character from one field to the next changes the id
        ->and(MessageIds::forEffect('binding-1e', 'ffect-1', $token))->not->toBe($id);
});

it('generates random canonical version-4 ids', function () {
    $ids = array_map(fn () => MessageIds::random(), range(1, 200));

    expect(array_unique($ids))->toHaveCount(200);
    foreach ($ids as $id) {
        expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
    }
});

it('refuses a resumed provider message id that APNs would reject', function (string $id) {
    expect(fn () => new SendItem(ApnsDeviceToken::of(str_repeat('ab', 32)), providerMessageId: $id))->toThrow(InvalidArgumentException::class);
})->with(['uppercase' => ['886313E1-3B8A-5372-9B90-0C9AEE199E5D'], 'no dashes' => ['886313e13b8a53729b900c9aee199e5d'], 'empty' => ['']]);
