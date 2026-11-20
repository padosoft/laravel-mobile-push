<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Privacy\Redactor;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Laravel\Logging\LogRecordFactory;
use Padosoft\MobilePush\Laravel\Logging\PayloadLevel;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Logging\Support\LoggingFixtures as F;
use Padosoft\MobilePush\Tests\Support\FakeClock;

function factory(PayloadLevel $level = PayloadLevel::None): LogRecordFactory
{
    return new LogRecordFactory(new Redactor, new TokenIndex(new KeyRing([1 => str_repeat('k', 32)])), new FakeClock, $level);
}

it('removes every sensitive shape from provider text', function (string $template, string $secret) {
    $token = 'CANARYTOKEN'.str_repeat('a1B2', 12);
    $raw = str_replace(['{token}', '{secret}'], [$token, $secret], $template);

    $record = factory()->make(F::binding(), F::result($token, SendStatus::Rejected, $raw, ReasonCode::DestinationRejected), ['secrets' => [$secret]]);

    expect((string) $record->reason)->not->toContain($token)
        ->and((string) $record->reason)->not->toContain($secret);
})->with([
    'token echoed' => ['registration token {token} is not valid', 'unused-secret-value'],
    'bearer' => ['Authorization: Bearer {secret}', 'ya29.abcdefghijklmnop'],
    'registered secret in prose' => ['denied for {secret} sorry', 'rcpt-123456-marker'],
    'jwt' => ['jwt eyJhbGciOiJFUzI1NiJ9.eyJpc3MiOiJ4In0.c2ln then {secret}', 'abcdef-secret'],
]);

it('keeps control characters out and bounds every string', function () {
    $record = factory()->make(F::binding(), F::result(F::token(1)), [
        'origin_type' => "App\\User\r\nInjected: line".str_repeat('x', 500),
        'push_type' => 'Not A Valid Vocabulary Word!!',
        'traffic_class' => 'Transactional',
    ]);

    expect($record->originType)->not->toContain("\n")
        ->and(strlen((string) $record->originType))->toBe(191)
        ->and($record->pushType)->toBe('other')
        ->and($record->trafficClass)->toBe('transactional')
        ->and($record->operation)->toBe('send');
});

it('indexes the destination per scope and never exposes the raw value', function () {
    $a = factory()->make(F::binding(), F::result(F::token(1)));
    $b = factory()->make(new BindingRef('tenant-b', 'main', Provider::Fcm, 'demo-project', Environment::Production), F::result(F::token(1)));

    expect($a->destinationIndex)->not->toBe($b->destinationIndex)
        ->and(json_encode($a->toArray()))->not->toContain(F::token(1));
});

it('builds the payload view per level', function (PayloadLevel $level, mixed $expected) {
    $record = factory($level)->make(F::binding(), F::result(F::token(1)), ['payload' => ['a' => ['b' => 'v', 'c' => [1, 2]], 'password' => 'p']]);

    expect($record->payload)->toBe($expected);
})->with([
    'none' => [PayloadLevel::None, null],
    'keys' => [PayloadLevel::Keys, ['a.b', 'a.c', 'password']],
    'full' => [PayloadLevel::Full, ['a' => ['b' => 'v', 'c' => [1, 2]], 'password' => '[redacted]']],
]);
