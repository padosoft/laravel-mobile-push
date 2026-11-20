<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Fcm\FcmConfig;
use Padosoft\MobilePush\Core\Fcm\FcmDriver;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Policy\EndpointRole;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Story: one server hosts two iOS apps of two different teams, and two connection aliases of the same Firebase
 * project. Connections must never mix credentials; quotas must follow the real provider account.
 */
it('never lets two apps with different credentials share a connection pool, on the same APNs host', function () {
    $kit = new SendKit;
    $shop = $kit->apnsDriver($kit->apnsCredential('SHOPKEY111', 'TEAMSHOP11'), binding: SendKit::apnsBinding('shop', bundle: 'com.example.shop'));
    $news = $kit->apnsDriver($kit->apnsCredential('NEWSKEY111', 'TEAMNEWS11'), binding: SendKit::apnsBinding('news', bundle: 'com.example.news'));
    $transport = new FakeTransport;
    $sender = $kit->sender($transport);

    $sender->send($shop, PushMessage::make('A', 'B'), [SendKit::apnsToken(1)], SendKit::live())->counts();
    $sender->send($news, PushMessage::make('A', 'B'), [SendKit::apnsToken(1)], SendKit::live())->counts();

    [$a, $b] = $transport->recorded();
    expect(parse_url($a['request']->url, PHP_URL_HOST))->toBe(parse_url($b['request']->url, PHP_URL_HOST))
        ->and($a['profile']->poolKey)->not->toBe($b['profile']->poolKey)
        ->and($a['request']->headers['authorization'])->not->toBe($b['request']->headers['authorization'])
        ->and($shop->accountKey())->not->toBe($news->accountKey());
});

it('lets two tenants that share one credential share its pool, and keeps send and broadcast publication apart', function () {
    $kit = new SendKit;
    $acme = $kit->apnsDriver($kit->apnsCredential(scope: 'acme'), binding: SendKit::apnsBinding('ios', 'acme'));
    $beta = $kit->apnsDriver($kit->apnsCredential(scope: 'beta'), binding: SendKit::apnsBinding('ios', 'beta'));

    expect($acme->connectionProfile(EndpointRole::ApnsSend, 0)->poolKey)->toBe($beta->connectionProfile(EndpointRole::ApnsSend, 0)->poolKey)
        ->and($acme->connectionProfile(EndpointRole::ApnsSend, 0)->poolKey)->not->toBe($acme->connectionProfile(EndpointRole::ApnsBroadcastPublish, 0)->poolKey)
        ->and($acme->binding()->identity())->not->toBe($beta->binding()->identity());
});

it('counts the traffic of two aliases of one Firebase project against one rate limit', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->defaultTo(SendKit::fcmOk());
    $sender = $kit->sender($transport, $kit->guards(ratePerMinute: 10));
    $alias = fn (string $name, string $email) => new FcmDriver(new FcmConfig(
        SendKit::fcmBinding($name),
        $kit->fcmTokenProvider(SendKit::oauthAnswering('ya29.fake-'.$name), $email),
    ), $kit->clock);
    $marketing = $alias('marketing', 'marketing@example-project.iam.gserviceaccount.com');
    $orders = $alias('orders', 'orders@example-project.iam.gserviceaccount.com');
    $tokens = fn (int $from) => array_map(fn ($i) => SendKit::fcmToken($i), range($from, $from + 7));

    $first = SendKit::all($sender->send($marketing, PushMessage::make('A', 'B'), $tokens(0), SendKit::live()));
    $second = SendKit::all($sender->send($orders, PushMessage::make('A', 'B'), $tokens(100), SendKit::live()));

    $statuses = array_count_values(array_map(fn ($r) => $r->status->value, [...$first, ...$second]));
    expect($marketing->accountKey())->toBe($orders->accountKey())
        ->and($marketing->connectionProfile(EndpointRole::FcmApi, 0)->poolKey)->not->toBe($orders->connectionProfile(EndpointRole::FcmApi, 0)->poolKey)
        ->and($transport->requests())->toHaveCount(10)
        ->and($statuses)->toBe(['accepted' => 10, 'deferred' => 6])
        ->and(array_map(fn ($r) => $r->reason->code, array_slice($second, 2)))->each->toBe(ReasonCode::QuotaExceeded)
        ->and(array_map(fn ($r) => $r->nextAttemptAt !== null && $r->nextAttemptAt > $kit->clock->now(), array_slice($second, 2)))->each->toBeTrue();
});
