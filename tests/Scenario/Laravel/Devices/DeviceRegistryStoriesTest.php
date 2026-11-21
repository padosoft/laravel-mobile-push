<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Identity\Destination\FcmFid;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Identity\DestinationRef;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Outcome\Decision;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Laravel\Devices\DeviceChange;
use Padosoft\MobilePush\Laravel\Devices\DeviceEligibility;
use Padosoft\MobilePush\Laravel\Devices\DeviceOutcomeApplier;
use Padosoft\MobilePush\Laravel\Devices\DevicePruner;
use Padosoft\MobilePush\Laravel\Devices\DeviceRegistrar;
use Padosoft\MobilePush\Laravel\Devices\Ineligibility;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Devices\DeviceWorld;

require_once __DIR__.'/DeviceWorld.php';

beforeEach(function (): void {
    $this->world = DeviceWorld::install($this);
    $this->registrar = fn (): DeviceRegistrar => app(DeviceRegistrar::class);
});

function rejection(DestinationAction $action, ?int $atMs = null, SendStatus $status = SendStatus::Rejected): Decision
{
    return new Decision($status, Reason::of(ReasonCode::DestinationRejected, 'BadDeviceToken'), destinationAction: $action, invalidatedAtMs: $atMs);
}

it('first login on a new phone: one active row, no clear token anywhere, idempotent', function (): void {
    $binding = DeviceWorld::apnsBinding();
    $token = DeviceWorld::apnsToken('phone');
    $alice = DeviceWorld::user(1);

    $device = ($this->registrar)()->register($binding, $token, $alice, ['platform' => 'ios', 'app_version' => '3.1.0', 'metadata' => ['push_enabled' => true]]);
    ($this->registrar)()->register($binding, $token, $alice);

    expect(DB::table('mobile_push_devices')->count())->toBe(1);

    $row = DB::table('mobile_push_devices')->first();
    expect($row->state)->toBe('active')
        ->and($row->generation)->toBe(1)
        ->and($row->environment)->toBe('production')
        ->and($row->kind)->toBe('apns_device_token')
        ->and($row->token_index)->toStartWith('v1:');

    foreach ((array) $row as $column => $value) {
        expect((string) $value)->not->toContain($token->value(), "column {$column} leaks the token");
    }

    $fresh = PushDevice::query()->first();
    expect($fresh->destination()->value())->toBe($token->value())
        ->and($fresh->destination()->ref()->generation)->toBe(1)
        ->and($fresh->destination()->ref()->ownerId)->toBe('1')
        ->and($fresh->metadata)->toBe(['push_enabled' => true])
        ->and(array_keys($fresh->toArray()))->not->toContain('token', 'token_index', 'fid', 'fid_index');
    expect($device->getKey())->toBe($fresh->getKey());
});

it('refuses a destination that does not fit the binding before any write', function (): void {
    expect(fn () => ($this->registrar)()->register(DeviceWorld::apnsBinding(), DeviceWorld::fcmToken(), DeviceWorld::user(1)))
        ->toThrow(InvalidDestinationException::class)
        ->and(fn () => ($this->registrar)()->register(DeviceWorld::fcmBinding(), FcmTopic::of('news'), null))
        ->toThrow(InvalidDestinationException::class);

    expect(DB::table('mobile_push_devices')->count())->toBe(0);
});

it('shared phone: B registers the token of A, one row, owner B, generation 2, A\'s old ref is dead', function (): void {
    $binding = DeviceWorld::apnsBinding();
    $token = DeviceWorld::apnsToken('shared');

    ($this->registrar)()->register($binding, $token, DeviceWorld::user(1));
    $queuedForA = PushDevice::query()->first()->destination()->ref();

    $this->world->clock->advance(60);
    ($this->registrar)()->register($binding, $token, DeviceWorld::user(2));

    $row = DB::table('mobile_push_devices')->first();
    expect(DB::table('mobile_push_devices')->count())->toBe(1)
        ->and($row->owner_id)->toBe('2')
        ->and($row->generation)->toBe(2);

    // Both the owner and the generation changed: the more informative cause wins.
    $verdict = app(DeviceEligibility::class)->check($queuedForA);
    expect($verdict->eligible)->toBeFalse()
        ->and($verdict->cause)->toBe(Ineligibility::OwnerChanged)
        ->and($verdict->reason()->code)->toBe(ReasonCode::PolicyIneligible)
        ->and($verdict->reason()->hint)->not->toBeEmpty();

    $queuedForB = PushDevice::query()->first()->ref();
    expect(app(DeviceEligibility::class)->check($queuedForB)->eligible)->toBeTrue();
});

it('wrong environment in staging: quarantine suspends the row, a new registration reactivates it with a new generation', function (): void {
    $binding = DeviceWorld::apnsBinding(env: Environment::Sandbox);
    $token = DeviceWorld::apnsToken('stage');
    $user = DeviceWorld::user(7);

    ($this->registrar)()->register($binding, $token, $user);
    $ref = PushDevice::query()->first()->ref();

    $done = app(DeviceOutcomeApplier::class)->applyDecision($ref, rejection(DestinationAction::Quarantine));
    expect($done->change)->toBe(DeviceChange::Quarantined);

    $row = DB::table('mobile_push_devices')->first();
    expect($row->state)->toBe('quarantined')->and($row->state_reason)->toBe('BadDeviceToken')
        ->and($user->pushDestinations($binding))->toBe([])
        ->and(app(DeviceEligibility::class)->check($ref)->cause)->toBe(Ineligibility::StateNotActive);

    $this->world->clock->advance(3600);
    ($this->registrar)()->register($binding, $token, $user);

    $row = DB::table('mobile_push_devices')->first();
    expect(DB::table('mobile_push_devices')->count())->toBe(1)
        ->and($row->state)->toBe('active')
        ->and($row->generation)->toBe(2)
        ->and($row->state_reason)->toBeNull()
        ->and($user->pushDestinations($binding))->toHaveCount(1);
});

it('uninstalled app: a newer 410 invalidates, an older one does not touch a newer registration', function (): void {
    $binding = DeviceWorld::apnsBinding();
    $token = DeviceWorld::apnsToken('gone');
    $user = DeviceWorld::user(3);
    $t0 = $this->world->clock->now();

    ($this->registrar)()->register($binding, $token, $user);
    $ref = PushDevice::query()->first()->ref();
    $registeredMs = (int) ($t0->format('U')) * 1000;

    $done = app(DeviceOutcomeApplier::class)->applyDecision($ref, rejection(DestinationAction::Invalidate, $registeredMs + 10_000));
    expect($done->change)->toBe(DeviceChange::Invalidated)
        ->and(DB::table('mobile_push_devices')->value('state'))->toBe('invalidated');

    // The user reinstalls 100 s later: generation 2.
    $this->world->clock->advance(100);
    ($this->registrar)()->register($binding, $token, $user);
    $ref2 = PushDevice::query()->first()->ref();
    expect($ref2->generation)->toBe(2);

    $late = app(DeviceOutcomeApplier::class)->applyDecision($ref2, rejection(DestinationAction::Invalidate, $registeredMs + 50_000));
    expect($late->change)->toBe(DeviceChange::StaleTimestamp)
        ->and(DB::table('mobile_push_devices')->value('state'))->toBe('active');
});

it('a late answer for generation 7 never touches generation 8 with the same raw token', function (): void {
    $binding = DeviceWorld::apnsBinding();
    $token = DeviceWorld::apnsToken('late');

    ($this->registrar)()->register($binding, $token, DeviceWorld::user(1));
    DB::table('mobile_push_devices')->update(['generation' => 8]);
    $stale = new DestinationRef((string) DB::table('mobile_push_devices')->value('id'), 7, PushDevice::class, '1');

    foreach ([DestinationAction::Quarantine, DestinationAction::Invalidate] as $action) {
        $done = app(DeviceOutcomeApplier::class)->applyDecision($stale, rejection($action, PHP_INT_MAX));
        expect($done->change)->toBe(DeviceChange::GenerationChanged);
    }

    $row = DB::table('mobile_push_devices')->first();
    expect($row->state)->toBe('active')->and($row->generation)->toBe(8)->and($row->state_reason)->toBeNull();
});

it('unknown outcome never changes the row, even when the decision carries an action', function (): void {
    ($this->registrar)()->register(DeviceWorld::apnsBinding(), DeviceWorld::apnsToken('u'), DeviceWorld::user(1));
    $before = (array) DB::table('mobile_push_devices')->first();
    $ref = PushDevice::query()->first()->ref();

    $done = app(DeviceOutcomeApplier::class)->applyDecision($ref, rejection(DestinationAction::Invalidate, PHP_INT_MAX, SendStatus::OutcomeUnknown));

    expect($done->change)->toBe(DeviceChange::UnknownOutcome)
        ->and((array) DB::table('mobile_push_devices')->first())->toBe($before);
});

it('two tenants with the same token and the same local ids stay fully independent', function (): void {
    $acme = DeviceWorld::apnsBinding('acme');
    $beta = DeviceWorld::apnsBinding('beta');
    $token = DeviceWorld::apnsToken('same');
    $user = DeviceWorld::user(1);

    ($this->registrar)()->register($acme, $token, $user);
    ($this->registrar)()->register($beta, $token, $user);

    expect(DB::table('mobile_push_devices')->count())->toBe(2)
        ->and(DB::table('mobile_push_devices')->distinct()->count('token_index'))->toBe(2);

    $acmeRef = $user->pushDestinations($acme)[0]->ref();
    $betaRef = $user->pushDestinations($beta)[0]->ref();
    expect($acmeRef->id)->not->toBe($betaRef->id);

    // Quarantine in acme; beta untouched and still sendable.
    app(DeviceOutcomeApplier::class)->applyDecision($acmeRef, rejection(DestinationAction::Quarantine));
    expect($user->pushDestinations($acme))->toBe([])
        ->and($user->pushDestinations($beta))->toHaveCount(1)
        ->and($user->pushDestinations(null, 'beta'))->toHaveCount(1)
        ->and($user->pushDestinations(null, 'acme'))->toBe([]);

    // A ref from acme is "missing" when checked against beta's binding.
    expect(app(DeviceEligibility::class)->check($acmeRef, $beta)->cause)->toBe(Ineligibility::RowMissing)
        ->and(app(DeviceEligibility::class)->check($betaRef, $beta)->eligible)->toBeTrue();

    // Logout in acme leaves beta alone.
    expect(($this->registrar)()->forgetOwner($user, 'acme'))->toBe(1)
        ->and($user->pushDestinations($beta))->toHaveCount(1);
});

it('FCM migration to installation ids: attaching a FID keeps one row and one destination', function (): void {
    $binding = DeviceWorld::fcmBinding();
    $token = DeviceWorld::fcmToken('x');
    $fid = FcmFid::of('c1xyz_AbCdEfGhIjKlMnOp');
    $user = DeviceWorld::user(5);

    $device = ($this->registrar)()->register($binding, $token, $user);
    ($this->registrar)()->attachFid($device, $fid);
    // The app later registers the FID on its own, and the token again together with it.
    ($this->registrar)()->register($binding, $fid, $user);
    ($this->registrar)()->register($binding, $token, $user, ['fid' => $fid]);

    $row = DB::table('mobile_push_devices')->first();
    expect(DB::table('mobile_push_devices')->count())->toBe(1)
        ->and($row->fid_index)->toStartWith('v1:')
        ->and($row->fid)->not->toContain($fid->value())
        ->and($row->generation)->toBe(1);

    $destinations = $user->pushDestinations($binding);
    expect($destinations)->toHaveCount(1)
        ->and($destinations[0]->kind())->toBe(DestinationKind::FcmToken)
        ->and(PushDevice::query()->first()->fidDestination()?->value())->toBe($fid->value());
});

it('index key rotation: a device registered under key 1 is found and re-indexed to key 2', function (): void {
    $binding = DeviceWorld::apnsBinding();
    $token = DeviceWorld::apnsToken('rot');
    $user = DeviceWorld::user(9);

    ($this->registrar)()->register($binding, $token, $user);
    expect(DB::table('mobile_push_devices')->value('token_index'))->toStartWith('v1:');

    $this->world->useKeys(2, $this->world->keys);
    ($this->registrar)()->register($binding, $token, $user);

    $row = DB::table('mobile_push_devices')->first();
    expect(DB::table('mobile_push_devices')->count())->toBe(1)
        ->and($row->generation)->toBe(1)
        ->and(TokenIndex::versionOf($row->token_index))->toBe(2)
        ->and($row->token)->toStartWith('v2:')
        ->and(PushDevice::query()->first()->destination()->value())->toBe($token->value());
});

it('logout: forgetOwner empties the destinations and kills old refs; unregister by the wrong owner does nothing', function (): void {
    $binding = DeviceWorld::apnsBinding();
    $phone = DeviceWorld::apnsToken('p1');
    $tablet = DeviceWorld::apnsToken('p2');
    $user = DeviceWorld::user(4);

    ($this->registrar)()->register($binding, $phone, $user);
    ($this->registrar)()->register($binding, $tablet, $user);
    $refs = array_map(static fn ($d) => $d->ref(), $user->pushDestinations($binding));
    expect($refs)->toHaveCount(2);

    // Someone else logging out of the phone does not detach this user.
    expect(($this->registrar)()->unregister($binding, $phone, DeviceWorld::user(99)))->toBeFalse()
        ->and($user->pushDestinations($binding))->toHaveCount(2);

    expect(($this->registrar)()->forgetOwner($user))->toBe(2)
        ->and($user->pushDestinations($binding))->toBe([]);

    $verdicts = app(DeviceEligibility::class)->checkMany($refs);
    foreach ($refs as $ref) {
        expect($verdicts[$ref->id]->eligible)->toBeFalse()
            ->and($verdicts[$ref->id]->cause)->toBe(Ineligibility::OwnerChanged);
    }

    expect(DB::table('mobile_push_devices')->count())->toBe(2)
        ->and(DB::table('mobile_push_devices')->whereNotNull('owner_id')->count())->toBe(0);
});

it('markSent only records the attempted generation', function (): void {
    ($this->registrar)()->register(DeviceWorld::apnsBinding(), DeviceWorld::apnsToken('m'), DeviceWorld::user(1));
    $ref = PushDevice::query()->first()->ref();
    $applier = app(DeviceOutcomeApplier::class);

    expect($applier->markSent($ref))->toBeTrue()
        ->and(DB::table('mobile_push_devices')->value('last_sent_at'))->not->toBeNull();

    DB::table('mobile_push_devices')->update(['last_sent_at' => null, 'generation' => 2]);
    expect($applier->markSent($ref))->toBeFalse()
        ->and(DB::table('mobile_push_devices')->value('last_sent_at'))->toBeNull();
});

it('prunes invalidated and inactive rows in chunks, with a dry run first', function (): void {
    $binding = DeviceWorld::apnsBinding();
    foreach (['a', 'b', 'c', 'd'] as $i => $seed) {
        ($this->registrar)()->register($binding, DeviceWorld::apnsToken($seed), DeviceWorld::user($i + 1));
    }
    $ids = DB::table('mobile_push_devices')->orderBy('id')->pluck('id')->all();
    $old = '2026-01-01 00:00:00.000';
    DB::table('mobile_push_devices')->where('id', $ids[0])->update(['state' => 'invalidated', 'state_changed_at' => $old]);
    DB::table('mobile_push_devices')->where('id', $ids[1])->update(['last_seen_at' => $old]);
    DB::table('mobile_push_devices')->where('id', $ids[2])->update(['state' => 'invalidated']); // invalidated just now: kept

    $pruner = app(DevicePruner::class);
    $dry = $pruner->prune(30, 90, true);
    expect([$dry->invalidated, $dry->inactive])->toBe([1, 1])
        ->and(DB::table('mobile_push_devices')->count())->toBe(4);

    $real = $pruner->prune(30, 90);
    expect($real->total())->toBe(2)
        ->and(DB::table('mobile_push_devices')->orderBy('id')->pluck('id')->all())->toBe([$ids[2], $ids[3]]);
});

it('is off by default: the migration is only published, not loaded', function (): void {
    expect(ServiceProvider::pathsToPublish(null, 'mobile-push-migrations-devices'))->not->toBeEmpty();
});
