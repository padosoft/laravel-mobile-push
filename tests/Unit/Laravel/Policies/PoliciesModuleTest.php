<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Driver\GateContext;
use Padosoft\MobilePush\Core\Driver\SendItem;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Policy\Badge\BadgeResolver;
use Padosoft\MobilePush\Core\Policy\Frequency\FrequencyCapGate;
use Padosoft\MobilePush\Laravel\Policies\PolicyGates;
use Padosoft\MobilePush\Tests\Scenario\Policy\Frequency\PolicyKit;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

it('offers no gate by default (the cap is opt-in) and the frequency gate once it is enabled', function () {
    expect($this->app->make(PolicyGates::class)->gates())->toBe([]);

    $this->app->forgetInstance(PolicyGates::class);
    config(['mobile-push.frequency.enabled' => true]);
    $gates = $this->app->make(PolicyGates::class)->gates();
    expect($gates)->toHaveCount(1)->and($gates[0])->toBeInstanceOf(FrequencyCapGate::class);
});

it('reads limits, no-policy bucket and badge settings from config', function () {
    config([
        'mobile-push.frequency.enabled' => true,
        'mobile-push.frequency.limits' => ['default' => ['per_hour' => 1], 'marketing' => ['per_day' => 1]],
        'mobile-push.frequency.no_policy_bucket' => null,
        'mobile-push.badge.per_binding' => true,
    ]);
    $gate = $this->app->make(PolicyGates::class)->gates()[0];
    $kit = new PolicyKit;
    $context = fn (PushMessage $m, string $effect) => new GateContext(SendKit::apnsBinding(), $m, TrafficClass::Bulk, $kit->clock->now(), $effect);
    $item = [new SendItem(PolicyKit::device(1, 'u1'))];

    // no policy and no_policy_bucket null: never capped
    expect($gate->check($context(PushMessage::make('a'), 'e1'), $item))->toBe([])
        ->and($gate->check($context(PushMessage::make('a'), 'e2'), $item))->toBe([])
        ->and($gate->check($context(PushMessage::make('a')->policy('marketing'), 'e3'), $item))->toBe([])
        ->and($gate->check($context(PushMessage::make('a')->policy('marketing'), 'e4'), $item))->toHaveCount(1);

    $resolver = $this->app->make(BadgeResolver::class);
    $binding = SendKit::apnsBinding('ios');
    expect($resolver->subjectKey('o:u', $binding))->toBe('o:u#ios');
});

dataset('invalid frequency settings', [
    'failure policy' => ['frequency.on_store_failure', 'maybe'],
    'limits' => ['frequency.limits', ['default' => ['per_day' => 0]]],
]);

it('rejects an invalid failure policy and an invalid limit table once the cap is enabled', function (string $key, mixed $value) {
    config(['mobile-push.frequency.enabled' => true, 'mobile-push.'.$key => $value]);
    $this->app->make(PolicyGates::class);
})->with('invalid frequency settings')->throws(ConfigurationException::class);

it('does not validate the frequency settings while the cap is disabled (they are not used)', function (string $key, mixed $value) {
    config(['mobile-push.frequency.enabled' => false, 'mobile-push.'.$key => $value]);

    expect($this->app->make(PolicyGates::class)->gates())->toBe([]);
})->with('invalid frequency settings');

it('can switch the badge counter off', function () {
    config(['mobile-push.badge.enabled' => false]);
    $message = PushMessage::make('a')->incrementBadge();

    expect($this->app->make(BadgeResolver::class)->resolve($message, 'default', 'o:u', 'e1'))->toBe($message);
});
