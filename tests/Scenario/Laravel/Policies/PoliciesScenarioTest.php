<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Policy\Badge\BadgeResolver;
use Padosoft\MobilePush\Core\Policy\Frequency\Subject;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Laravel\Policies\PolicyGates;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\PolicyKit;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Story: the app is wired from config on the Laravel cache. A user with two phones gets two promos (limit 2 a day)
 * and a third one is dropped; each order notification carries the unread count.
 */
it('caps and counts through the container wiring on the cache store', function () {
    config(['mobile-push.frequency.limits' => ['default' => ['per_day' => 2]]]);
    $kit = new PolicyKit;
    $gates = $this->app->make(PolicyGates::class)->gates();
    $devices = [PolicyKit::device(1, 'u1'), PolicyKit::device(2, 'u1')];
    $sent = [];

    foreach (['p1', 'p2', 'p3'] as $effect) {
        $transport = new FakeTransport;
        SendKit::all($kit->send->sender($transport)->send(
            $kit->send->apnsDriver(),
            PushMessage::make('Sale')->policy('marketing'),
            $devices,
            SendKit::live(trafficClass: TrafficClass::Bulk, gates: $gates, effectKey: $effect),
        ));
        $sent[] = $transport->sentCount();
    }

    $resolver = $this->app->make(BadgeResolver::class);
    $subject = Subject::owner('App\\Models\\User', 'u1');
    $badges = [
        $resolver->resolve(PushMessage::make('o')->incrementBadge(), 'default', $subject, 'o1')->badge,
        $resolver->resolve(PushMessage::make('o')->incrementBadge(), 'default', $subject, 'o2')->badge,
        $resolver->resolve(PushMessage::make('o')->incrementBadge(), 'default', $subject, 'o2')->badge,
    ];

    expect($sent)->toBe([2, 2, 0])->and($badges)->toBe([1, 2, 2]);
});
