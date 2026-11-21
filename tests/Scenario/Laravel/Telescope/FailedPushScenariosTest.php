<?php

declare(strict_types=1);

use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\Storage\EntryQueryOptions;
use Laravel\Telescope\Telescope;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Laravel\Telescope\PushWatcher;
use Padosoft\MobilePush\Laravel\Telescope\TelescopeModule;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Pulse\Canary;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Pulse\Kit;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Telescope\TelescopeWorld;

beforeEach(fn () => TelescopeWorld::build($this->app));

afterEach(function (): void {
    Telescope::stopRecording();   // static state: left on, later tests would record into memory
    Telescope::flushEntries();
});

it('shows the failed push in Telescope with its tags and content', function () {
    $binding = Kit::binding('ios-main');
    event(Kit::result(
        $binding,
        SendStatus::Rejected,
        ReasonCode::DestinationUnregistered,
        hint: 'The token is no longer valid: stop sending to it.',
        messageId: 'apns-msg-42',
    ));

    $entries = TelescopeWorld::stored($this->app, 'push-status:rejected');

    expect($entries)->toHaveCount(1);
    $entry = $entries->first();
    expect($entry->content['name'])->toBe('mobile-push.result')
        ->and($entry->content['payload'])->toMatchArray([
            'connection' => 'ios-main',
            'provider' => 'apns',
            'environment' => 'production',
            'status' => 'rejected',
            'reason_code' => 'destination_unregistered',
            'hint' => 'The token is no longer valid: stop sending to it.',
            'http_status' => 400,
            'provider_message_id' => 'apns-msg-42',
            'delivery_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
        ])
        ->and($entry->content['payload']['destination']['kind'])->toBe('fcm_token')
        ->and($entry->content['listeners'])->toBe([]) // the Events screen reads listeners.length
        ->and($entry->type)->toBe(EntryType::EVENT);

    expect($entry->tags)->toContain('mobile-push', 'push:ios-main', 'push-provider:apns', 'push-status:rejected', 'push-reason:destination_unregistered');
});

it('does not create a per-result entry for an accepted transactional result', function () {
    event(Kit::result(Kit::binding(), SendStatus::Accepted, class: TrafficClass::Transactional));

    expect(TelescopeWorld::stored($this->app))->toHaveCount(0);
});

it('records one entry per batch with counters, duration and one tag per status present', function () {
    $binding = Kit::binding('android-main', Provider::Fcm);
    event(Kit::batch($binding, ['accepted' => 90, 'rejected' => 7, 'outcome_unknown' => 3], 412.345));

    $entries = TelescopeWorld::stored($this->app, 'push:android-main');

    expect($entries)->toHaveCount(1);
    $entry = $entries->first();
    expect($entry->content['name'])->toBe('mobile-push.batch')
        ->and($entry->content['payload'])->toMatchArray([
            'connection' => 'android-main',
            'provider' => 'fcm',
            'total' => 100,
            'counts' => ['accepted' => 90, 'rejected' => 7, 'outcome_unknown' => 3],
            'duration_ms' => 412.35,
        ])
        ->and($entry->tags)->toContain('mobile-push', 'push-provider:fcm', 'push-status:accepted', 'push-status:rejected', 'push-status:outcome_unknown')
        ->and($entry->tags)->not->toContain('push-status:failed');
});

it('records the statuses that need attention and skips simulated and validated results', function (SendStatus $status, bool $recorded) {
    event(Kit::result(Kit::binding(), $status, ReasonCode::Unknown));

    expect(TelescopeWorld::stored($this->app))->toHaveCount($recorded ? 1 : 0);
})->with([
    [SendStatus::Rejected, true],
    [SendStatus::Failed, true],
    [SendStatus::Deferred, true],
    [SendStatus::OutcomeUnknown, true],
    [SendStatus::Expired, true],
    [SendStatus::Skipped, true],
    [SendStatus::Accepted, false],
    [SendStatus::Simulated, false],
    [SendStatus::Validated, false],
]);

it('never records a token, bearer, raw provider text, nor lets the generic event watcher do it', function () {
    $binding = Kit::binding('ios-main');
    event(Kit::result(
        $binding,
        SendStatus::Rejected,
        ReasonCode::DestinationRejected,
        token: Canary::TOKEN,
        raw: 'bad token '.Canary::TOKEN.' bearer '.Canary::BEARER.' '.Canary::RAW,
        hint: 'Apns said '.Canary::BEARER,
    ));
    event(Kit::batch($binding, ['rejected' => 1]));

    $repository = $this->app->make(EntriesRepository::class);
    Telescope::store($repository);
    $everything = json_encode(
        $repository->get(null, (new EntryQueryOptions)->limit(500)),
        JSON_THROW_ON_ERROR,
    );

    expect($everything)->toContain('mobile-push.result')->toContain('mobile-push.batch');
    foreach (Canary::all() as $canary) {
        expect($everything)->not->toContain($canary);
    }
    // The masked form of the destination is what an operator sees, not the value.
    expect($everything)->toContain('CANARY');  // sanity: the masked head of the token ("CANARY…cdef") is kept
    expect($everything)->not->toContain('PushResultProduced');
});

it('does not let a failing Telescope break sending, and counts the failure', function () {
    Telescope::filter(function () {
        throw new RuntimeException('telescope storage down');
    });
    $watcher = $this->app->make(PushWatcher::class);

    event(Kit::result(Kit::binding(), SendStatus::Rejected, ReasonCode::PayloadInvalid));
    event(Kit::batch(Kit::binding(), ['accepted' => 1]));

    expect($watcher->failures())->toBe(2);
    Telescope::$filterUsing = array_slice(Telescope::$filterUsing, 0, -1);
});

it('records nothing once mobile-push.telescope.enabled is false', function () {
    $this->app['config']->set('mobile-push.telescope.enabled', false);
    event(Kit::result(Kit::binding(), SendStatus::Rejected, ReasonCode::PayloadInvalid));
    event(Kit::batch(Kit::binding(), ['accepted' => 1]));

    expect(TelescopeWorld::stored($this->app))->toHaveCount(0);
});

it('records nothing when Telescope is not recording', function () {
    Telescope::stopRecording();
    event(Kit::result(Kit::binding(), SendStatus::Rejected, ReasonCode::PayloadInvalid));
    event(Kit::batch(Kit::binding(), ['accepted' => 1]));

    expect(TelescopeWorld::stored($this->app))->toHaveCount(0);
});

it('stays off when mobile-push.telescope.enabled is false', function (mixed $value, bool $active) {
    $this->app['config']->set('mobile-push.telescope.enabled', $value);

    expect(TelescopeModule::active($this->app))->toBe($active);
})->with([
    'auto' => ['auto', true],
    'true' => [true, true],
    'false' => [false, false],
    'string false' => ['false', false],
    'off' => ['off', false],
]);

it('is inactive when Telescope itself is disabled', function () {
    $this->app['config']->set('telescope.enabled', false);

    expect(TelescopeModule::active($this->app))->toBeFalse();
});

it('detects the optional package through its guard', function () {
    expect(TelescopeModule::telescopeInstalled())->toBeTrue();
});
