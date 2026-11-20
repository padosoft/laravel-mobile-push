<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsBroadcastChannel;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Policy\ExecutionMode;
use Padosoft\MobilePush\Core\Policy\LiveSettings;
use Padosoft\MobilePush\Core\Policy\ModeResolver;
use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Core\Policy\SafeModeGuard;
use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;

beforeEach(function () {
    $this->index = new TokenIndex(new KeyRing([1 => random_bytes(32)]));
    $this->guard = new SafeModeGuard($this->index);
    $this->apns = new BindingRef('default', 'main', Provider::Apns, 'com.example.App', Environment::Sandbox);
    $this->phone = ApnsDeviceToken::of(str_repeat('ab', 32));
    $this->stranger = ApnsDeviceToken::of(str_repeat('cd', 32));
});

it('A junior runs the first send on a laptop: simulated, with a hint on how to go live', function () {
    $mode = (new ModeResolver)->resolve('auto', 'local');
    $decision = $this->guard->decide($mode, 'local', $this->apns, $this->phone, OperationKind::Send, new LiveSettings);

    expect($mode)->toBe(ExecutionMode::Simulate)
        ->and($decision->allowed)->toBeFalse()
        ->and($decision->isSimulated())->toBeTrue()
        ->and($decision->reasonCode)->toBe(ReasonCode::Simulated)
        ->and($decision->hint)->toContain('MOBILE_PUSH_MODE=live')->toContain('live.allow')->toContain('live.only_to');
});

it('Staging with live sending enabled: only the allowlisted device passes', function () {
    $live = new LiveSettings(allow: true, onlyTo: [$this->index->index($this->phone, 'default')]);
    $go = fn (?Destination $d, OperationKind $op, ?BindingRef $b = null) => $this->guard->decide(ExecutionMode::Live, 'staging', $b ?? $this->apns, $d, $op, $live);
    $channel = ApnsBroadcastChannel::of('dHN0LXNyY2hubA==');

    expect($go($this->phone, OperationKind::Send)->allowed)->toBeTrue()
        ->and($go($this->phone, OperationKind::Validate)->allowed)->toBeTrue()
        ->and($go($this->stranger, OperationKind::Send)->isBlocked())->toBeTrue()
        ->and($go($channel, OperationKind::BroadcastPublish)->reasonCode)->toBe(ReasonCode::PolicySafeMode)
        ->and($go($channel, OperationKind::BroadcastPublish)->allowed)->toBeFalse()
        ->and($go($channel, OperationKind::ChannelManagement)->allowed)->toBeFalse()
        ->and($go(null, OperationKind::ChannelManagement)->allowed)->toBeFalse();

    $fcm = new BindingRef('default', 'android', Provider::Fcm, 'proj', Environment::Production);
    expect($go(FcmTopic::of('news'), OperationKind::Send, $fcm)->allowed)->toBeFalse()
        ->and($go(FcmToken::of('tok-123'), OperationKind::TopicSubscription, $fcm)->allowed)->toBeFalse();
});

it('Staging: explicit authorisations open topics, broadcast and channel management one by one', function () {
    $live = new LiveSettings(allow: true, allowBroadcast: true, allowChannelManagement: true);
    $d = fn ($dest, $op) => $this->guard->decide(ExecutionMode::Live, 'staging', $this->apns, $dest, $op, $live);

    expect($d(ApnsBroadcastChannel::of('dHN0LXNyY2hubA=='), OperationKind::BroadcastPublish)->allowed)->toBeTrue()
        ->and($d(null, OperationKind::ChannelManagement)->allowed)->toBeTrue()
        ->and($d($this->stranger, OperationKind::Send)->allowed)->toBeFalse();
});

it('Production live sending needs no allowlist', function () {
    $d = $this->guard->decide(ExecutionMode::Live, 'production', $this->apns, $this->stranger, OperationKind::Send, new LiveSettings);

    expect($d->allowed)->toBeTrue()->and($d->reasonCode)->toBe(ReasonCode::None);
});

it('Sandbox command, production configuration: blocked before any connection', function () {
    $prod = new BindingRef('default', 'main', Provider::Apns, 'com.example.App', Environment::Production);

    foreach (['production', 'staging'] as $env) {
        $d = $this->guard->decide(ExecutionMode::Live, $env, $prod, $this->phone, OperationKind::Send, new LiveSettings(allow: true, onlyTo: [$this->index->index($this->phone)]), Environment::Sandbox);

        expect($d->allowed)->toBeFalse()->and($d->isBlocked())->toBeTrue()->and($d->hint)->toContain('sandbox');
    }
});

it('An inconsistency blocks and never degrades into a real send', function () {
    $fcmToken = FcmToken::of('tok-123');
    $live = new LiveSettings(allow: true, onlyTo: [$this->index->index($this->phone)]);

    // FCM token on an APNs binding, even in production.
    expect($this->guard->decide(ExecutionMode::Live, 'production', $this->apns, $fcmToken, OperationKind::Send, $live)->isBlocked())->toBeTrue()
        // Broadcast publish to an individual device.
        ->and($this->guard->decide(ExecutionMode::Live, 'production', $this->apns, $this->phone, OperationKind::BroadcastPublish, $live)->isBlocked())->toBeTrue();
});

it('The allowlist is scoped: an index made for another scope does not match', function () {
    $live = new LiveSettings(allow: true, onlyTo: [$this->index->index($this->phone, 'other-tenant')]);

    expect($this->guard->decide(ExecutionMode::Live, 'staging', $this->apns, $this->phone, OperationKind::Send, $live)->allowed)->toBeFalse();
});
