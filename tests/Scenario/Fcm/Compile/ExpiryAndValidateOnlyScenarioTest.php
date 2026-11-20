<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Fcm\Message\AndroidConfig;
use Padosoft\MobilePush\Core\Fcm\Message\AndroidConfigV2;
use Padosoft\MobilePush\Core\Fcm\Message\FcmMessage;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Tests\Fixtures\fcm\FcmKit;

/*
 * Story: an effect is created at 10:00:00 with a logical expiry at 11:00:00. The send is attempted, fails
 * and is retried 20 minutes later: the retry must carry the REMAINING lifetime, never a fresh hour.
 */

it('recomputes the ttl from now at every attempt so a retry never extends the expiry', function () {
    $message = PushMessage::make('Flash sale', 'Ends soon');
    $expiresAt = '2026-11-20T11:00:00Z';

    $first = FcmKit::decode(FcmKit::compile($message, context: FcmKit::context('2026-11-20T10:00:00Z', $expiresAt)));
    $retry = FcmKit::decode(FcmKit::compile($message, context: FcmKit::context('2026-11-20T10:20:00Z', $expiresAt)));
    $late = FcmKit::decode(FcmKit::compile($message, context: FcmKit::context('2026-11-20T10:59:59Z', $expiresAt)));

    expect($first['message']['android']['ttl'])->toBe('3600s')
        ->and($retry['message']['android']['ttl'])->toBe('2400s')
        ->and($late['message']['android']['ttl'])->toBe('1s');
});

it('rounds the remaining lifetime down, never up', function () {
    $compiled = FcmKit::compile(
        PushMessage::make('a', 'b'),
        context: FcmKit::context('2026-11-20T10:00:00.900Z', '2026-11-20T10:00:02.100Z'),
    );

    expect(FcmKit::decode($compiled)['message']['android']['ttl'])->toBe('1s');
});

it('encodes zero remaining seconds as 0s, also when the expiry has already passed', function (string $now) {
    $compiled = FcmKit::compile(PushMessage::make('a', 'b'), context: FcmKit::context($now, '2026-11-20T10:00:00Z'));

    expect(FcmKit::decode($compiled)['message']['android']['ttl'])->toBe('0s');
})->with(['exactly at expiry' => '2026-11-20T10:00:00Z', 'after expiry' => '2026-11-20T10:05:00Z']);

it('caps the ttl at four weeks and says so', function () {
    $compiled = FcmKit::compile(PushMessage::make('a', 'b'), context: FcmKit::context('2026-11-20T10:00:00Z', '2027-03-01T00:00:00Z'));

    expect(FcmKit::decode($compiled)['message']['android']['ttl'])->toBe('2419200s')
        ->and($compiled->warnings)->toBe(['The remaining lifetime exceeds the FCM maximum of 4 weeks: ttl capped at 2419200s.']);
});

it('sends no ttl when there is no logical expiry', function () {
    expect(FcmKit::decode(FcmKit::compile(PushMessage::make('a', 'b')))['message']['android'])->toBe(['priority' => 'HIGH']);
});

it('puts the ttl where the message keeps its android configuration, and lets an explicit ttl win', function () {
    $context = FcmKit::context('2026-11-20T10:00:00Z', '2026-11-20T10:10:00Z');

    $native = FcmMessage::make('a', 'b');
    $v2 = FcmMessage::make('a', 'b')->androidV2(fn (AndroidConfigV2 $c) => $c->directBootOk());
    $explicit = FcmMessage::make('a', 'b')->android(fn (AndroidConfig $c) => $c->ttl(30));

    expect(FcmKit::decode(FcmKit::compile($native, context: $context))['message']['android'])->toBe(['ttl' => '600s'])
        ->and(FcmKit::decode(FcmKit::compile($v2, context: $context))['message']['android_v2'])->toBe(['direct_boot_ok' => true, 'ttl' => '600s'])
        ->and(FcmKit::decode(FcmKit::compile($explicit, context: $context))['message']['android']['ttl'])->toBe('30s');
});

it('asks Google to validate only, without delivering', function () {
    $compiled = FcmKit::compile(PushMessage::make('Hello', 'World'), context: FcmKit::context(validateOnly: true));
    $json = FcmKit::decode($compiled);

    expect($json['validate_only'])->toBeTrue()
        ->and(array_keys($json))->toBe(['message', 'validate_only'])
        ->and($compiled->meta['validate_only'])->toBe('true');
});
