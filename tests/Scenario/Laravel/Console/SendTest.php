<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Core\Privacy\TokenMasker;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Laravel\Console\Support\StdinReader;
use Padosoft\MobilePush\Laravel\Queue\SendPushJob;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Console\ConsoleWorld as C;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

it('sends to one token on an explicit platform, shows it masked and never prints it raw', function () {
    $world = W::install(C::both());
    $token = W::apnsToken('phone-1');

    [$code, $out] = C::run('mobile-push:send', ['tokens' => [$token], '--platform' => 'ios', '--title' => 'Hello', '--body' => 'World', '--data' => ['order=42']]);

    $requests = $world->pushRequests();
    expect($code)->toBe(0)
        ->and($requests)->toHaveCount(1)
        ->and($requests[0]->url)->toBe('https://api.push.apple.com/3/device/'.$token)
        ->and(json_decode($requests[0]->body, true)['order'])->toBe('42')
        ->and($out)->toContain(TokenMasker::mask($token))->toContain('accepted')->toContain('visible in the process list')
        ->and($out)->not->toContain($token)
        ->and($out)->not->toContain('ya29');
});

it('refuses to guess the platform when it cannot ask, and sends nothing', function () {
    $world = W::install(C::both());

    [$code, $out] = C::run('mobile-push:send', ['tokens' => [W::apnsToken('phone-1')], '--title' => 'Hello']);

    expect($code)->toBe(2)
        ->and($world->transport->sentCount())->toBe(0)
        ->and($out)->toContain('never guessed')->toContain('--platform');
});

it('proposes the platform in a terminal and sends only after a person confirms', function () {
    $world = W::install(C::both());
    $token = W::apnsToken('phone-1');

    $this->artisan('mobile-push:send', ['tokens' => [$token], '--title' => 'Hello'])
        ->expectsConfirmation('The 1 token(s) look like iOS (APNs) tokens. Send them as ios?', 'yes')
        ->assertExitCode(0);

    expect($world->pushRequests())->toHaveCount(1);
});

it('sends nothing when the person declines the proposed platform', function () {
    $world = W::install(C::both());

    $this->artisan('mobile-push:send', ['tokens' => [W::apnsToken('phone-1')], '--title' => 'Hello'])
        ->expectsConfirmation('The 1 token(s) look like iOS (APNs) tokens. Send them as ios?', 'no')
        ->assertExitCode(2);

    expect($world->transport->sentCount())->toBe(0);
});

it('reports a partial result when one of three tokens read from stdin is rejected', function () {
    $world = W::install(C::both());
    [$a, $b, $c] = [W::apnsToken('a'), W::apnsToken('b'), W::apnsToken('c')];
    $world->answer = static fn ($request) => str_ends_with($request->url, $b) ? new Response(400, [], '{"reason":"BadDeviceToken"}') : null;
    app()->instance(StdinReader::class, new StdinReader(static fn (): string => implode("\n", [$a, $b, $c])."\n"));

    [$code, $out] = C::run('mobile-push:send', ['--stdin' => true, '--platform' => 'ios', '--title' => 'Hi']);

    expect($code)->toBe(1)
        ->and($world->pushRequests())->toHaveCount(3)
        ->and($out)->toContain(TokenMasker::mask($a))->toContain(TokenMasker::mask($b))->toContain(TokenMasker::mask($c))
        ->and($out)->toContain('rejected')->toContain('destination_rejected (BadDeviceToken)')->toContain('quarantined')
        ->and($out)->toContain('2 accepted, 1 rejected')
        ->and($out)->not->toContain($b)->not->toContain('visible in the process list');
});

it('reads tokens from a file and prints JSON that a script can parse', function () {
    $world = W::install(C::both());
    $file = tempnam(sys_get_temp_dir(), 'tok');
    file_put_contents($file, "# staging devices\n".W::apnsToken('a')."\n\n".W::apnsToken('b')."\n".W::apnsToken('a')."\n");

    [$code, $out] = C::run('mobile-push:send', ['--file' => $file, '--connection' => 'ios', '--title' => 'Hi', '--json' => true]);
    @unlink($file);
    $data = json_decode($out, true);

    expect($code)->toBe(0)
        ->and($world->pushRequests())->toHaveCount(2)
        ->and($data['exit_code'])->toBe(0)
        ->and($data['results'])->toHaveCount(2)
        ->and($data['results'][0]['status'])->toBe('accepted')
        ->and($out)->not->toContain(W::apnsToken('a'));
});

it('exits 4 when the answer was lost: the outcome is unknown, not a failure', function () {
    $world = W::install(C::both());
    $world->transport->push(new TransportFailure(TransportEvidence::Unknown, 56, 'connection reset after the request'));

    [$code, $out] = C::run('mobile-push:send', ['tokens' => [W::apnsToken('a')], '--platform' => 'ios', '--title' => 'Hi']);

    expect($code)->toBe(4)->and($out)->toContain('outcome_unknown');
});

it('exits 3 with a prominent banner when sends are simulated, and opens no connection', function () {
    $world = W::install(C::both(), environment: 'local');
    config()->set('mobile-push.mode', 'auto');
    W::forgetSingletons();

    [$code, $out] = C::run('mobile-push:send', ['tokens' => [W::apnsToken('a')], '--platform' => 'ios', '--title' => 'Hi']);

    expect($code)->toBe(3)
        ->and($world->transport->sentCount())->toBe(0)
        ->and($out)->toContain('SIMULATED: NOTHING WAS SENT')->toContain('MOBILE_PUSH_MODE=live')->toContain('simulated');
});

it('sends to an FCM token and can have Google validate it only', function () {
    $world = W::install(C::both());

    [$code, $out] = C::run('mobile-push:send', ['tokens' => ['fake-android-token-1'], '--platform' => 'android', '--title' => 'Hi', '--validate-only' => true]);

    expect($code)->toBe(0)
        ->and(json_decode($world->requestsTo('fcm.googleapis.com')[0]->body, true)['validate_only'])->toBeTrue()
        ->and($out)->not->toContain('fake-android-token-1');

    [$code] = C::run('mobile-push:send', ['tokens' => [W::apnsToken('a')], '--platform' => 'ios', '--title' => 'Hi', '--validate-only' => true]);
    expect($code)->toBe(2);
});

it('rejects invalid input before touching the network', function (array $arguments, string $fragment) {
    $world = W::install(C::both());

    [$code, $out] = C::run('mobile-push:send', $arguments);

    expect($code)->toBe(2)->and($world->transport->sentCount())->toBe(0)->and($out)->toContain($fragment);
})->with([
    'no destination' => [['--platform' => 'ios', '--title' => 'Hi'], 'No destination'],
    'unknown platform' => [['tokens' => ['abc'], '--platform' => 'windows'], 'Unknown --platform'],
    'platform and connection disagree' => [['tokens' => ['abc'], '--platform' => 'ios', '--connection' => 'android'], 'does not match'],
    'tokens and topic' => [['tokens' => ['abc'], '--platform' => 'android', '--topic' => 'news'], 'one kind of destination'],
    'bad data' => [['tokens' => ['abc'], '--platform' => 'android', '--data' => ['oops']], 'key=value'],
    'bad badge' => [['tokens' => ['abc'], '--platform' => 'android', '--badge' => 'x'], '--badge'],
    'invalid apns token' => [['tokens' => ['not-hex'], '--platform' => 'ios', '--title' => 'Hi'], 'hexadecimal'],
    'unknown connection' => [['tokens' => ['abc'], '--connection' => 'nope'], 'nope'],
]);

it('never mixes platforms: a topic goes only to an FCM connection', function () {
    $world = W::install(C::both());

    [$code] = C::run('mobile-push:send', ['--topic' => 'news', '--platform' => 'ios', '--title' => 'Hi']);
    expect($code)->toBe(2)->and($world->transport->sentCount())->toBe(0);

    [$code, $out] = C::run('mobile-push:send', ['--topic' => 'news', '--title' => 'Hi']);
    expect($code)->toBe(0)->and($out)->toContain('news')
        ->and(json_decode($world->requestsTo('fcm.googleapis.com')[0]->body, true)['message']['topic'])->toBe('news');

    // Outside production a topic needs live.allow_topics.
    W::install(['connections' => ['android' => W::fcm(extra: ['live' => ['allow' => true]])]], environment: 'staging');
    [$code, $out] = C::run('mobile-push:send', ['--topic' => 'news', '--title' => 'Hi']);
    expect($code)->toBe(1)->and($out)->toContain('policy_safe_mode')->toContain('allow_topics');
});

it('can queue instead of sending', function () {
    $world = W::install(C::both());
    Queue::fake();

    [$code, $out] = C::run('mobile-push:send', ['tokens' => [W::apnsToken('a')], '--platform' => 'ios', '--title' => 'Hi', '--queue' => true]);

    expect($code)->toBe(0)->and($out)->toContain('Queued')->and($world->transport->sentCount())->toBe(0);
    Queue::assertPushed(SendPushJob::class, 1);
});
