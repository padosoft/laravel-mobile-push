<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Console\Ops\OpsWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/OpsWorld.php';

// The scenarios pin Carbon's clock to the fake clock: put the real one back when the application goes away.
beforeEach(function (): void {
    $this->beforeApplicationDestroyed(static fn () => Carbon::setTestNow());
});

/*
 * FCM topic subscriptions from the command line: one installation per API call, one result row per installation.
 */

/** @return list<string> three made-up registration tokens */
function topicTokens(): array
{
    return array_map(static fn (string $s): string => 'fake-registration-token-'.$s.'-'.str_repeat('Zq9', 20), ['one', 'two', 'three']);
}

it('subscribes three installations, one fails: exit 1 and a three-row table, no clear token printed', function (): void {
    $world = OpsWorld::boot();
    [$one, $two, $three] = topicTokens();
    $world->secret($one, $two, $three);
    $world->w->answer = static fn (Request $r): Response => str_contains($r->url, $two)
        ? SendKit::fcmError(400, 'INVALID_ARGUMENT', 'INVALID_ARGUMENT')
        : new Response(200, [], (string) json_encode(['topicName' => 'news']));

    $json = $world->json('mobile-push:fcm:topic-subscription', ['action' => 'subscribe', 'topic' => 'news', '--token' => [$one, $two, $three]]);
    [$exit, $output] = $world->call('mobile-push:fcm:topic-subscription', ['action' => 'subscribe', 'topic' => 'news', '--token' => [$one, $two, $three]]);

    expect($exit)->toBe(1)
        ->and($json['failed'])->toBe(1)
        ->and(array_column($json['installations'], 'result'))->toBe(['subscribed', 'failed', 'subscribed'])
        ->and($json['installations'][1]['http'])->toBe(400)
        ->and($json['installations'][1]['reason'])->not->toBeNull()
        ->and($output)->toContain('subscribed')->toContain('failed')->toContain('1 of 3 installation(s) failed');
    // Six calls, one per installation per run, all to the project's FCM registration endpoint.
    expect($world->w->pushRequests())->toHaveCount(6);
    foreach ($world->w->pushRequests() as $request) {
        expect($request->method)->toBe('POST')->and($request->url)->toContain('/topicSubscriptions?topicName=news');
    }
    $world->assertClean();
});

it('treats "already subscribed" as success and unsubscribe as idempotent', function (): void {
    $world = OpsWorld::boot();
    [$one] = topicTokens();
    $world->secret($one);
    $world->w->answer = static fn (Request $r): Response => $r->method === 'POST'
        ? SendKit::fcmError(409, 'ALREADY_EXISTS')
        : new Response(200, [], '{}');

    $subscribe = $world->json('mobile-push:fcm:topic-subscription', ['action' => 'subscribe', 'topic' => 'news', '--token' => [$one]]);
    [$exit] = $world->call('mobile-push:fcm:topic-subscription', ['action' => 'unsubscribe', 'topic' => 'news', '--token' => [$one]]);

    expect($subscribe['installations'][0]['result'])->toBe('already subscribed')
        ->and($subscribe['failed'])->toBe(0)
        ->and($exit)->toBe(0)
        ->and(array_map(static fn (Request $r): string => $r->method, $world->w->pushRequests()))->toBe(['POST', 'DELETE']);
});

it('lists the subscriptions of an installation, page after page', function (): void {
    $world = OpsWorld::boot();
    [$one] = topicTokens();
    $world->secret($one);
    $world->w->answer = static fn (Request $r): Response => str_contains($r->url, 'pageToken=page-2')
        ? new Response(200, [], (string) json_encode(['topicSubscriptions' => [['topicName' => 'weather', 'createTime' => '2026-11-01T10:00:00Z']]]))
        : new Response(200, [], (string) json_encode(['topicSubscriptions' => [['topicName' => 'news'], ['topicName' => 'sports']], 'nextPageToken' => 'page-2']));

    [$exit, $output] = $world->call('mobile-push:fcm:topic-subscription', ['action' => 'list', '--token' => [$one]]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('news')->toContain('sports')->toContain('weather')
        ->and($world->w->pushRequests())->toHaveCount(2)
        ->and($world->w->pushRequests()[0]->method)->toBe('GET');
    $json = $world->json('mobile-push:fcm:topic-subscription', ['action' => 'list', '--token' => [$one]]);
    expect(array_column($json['installations'][0]['topics'], 'topic'))->toBe(['news', 'sports', 'weather']);
    $world->assertClean();
});

it('accepts an FID next to tokens and never guesses what a string is', function (): void {
    $world = OpsWorld::boot();
    [$one] = topicTokens();
    $world->secret($one);

    $json = $world->json('mobile-push:fcm:topic-subscription', ['action' => 'subscribe', 'topic' => 'news', '--token' => [$one], '--fid' => ['cHpYtZxxFidInstall0001']]);

    expect(array_column($json['installations'], 'installation'))->toHaveCount(2)
        ->and($json['installations'][0]['installation'])->toStartWith('token ')
        ->and($json['installations'][1]['installation'])->toStartWith('fid ')
        ->and($world->w->pushRequests())->toHaveCount(2);
    $world->assertClean();
});

it('in simulation mode it sends nothing and says so', function (): void {
    $world = OpsWorld::boot(['mode' => 'simulate']);
    [$one, $two] = topicTokens();

    [$exit, $output] = $world->call('mobile-push:fcm:topic-subscription', ['action' => 'subscribe', 'topic' => 'news', '--token' => [$one, $two]]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('simulated')->toContain('Simulation mode: no request was sent')
        ->and($world->w->transport->sentCount())->toBe(0);
});

it('outside production, live topic subscriptions need explicit authorisation: blocked is a failure', function (): void {
    $world = OpsWorld::boot();
    app()['env'] = 'staging';
    SendingWorld::forgetSingletons();
    [$one] = topicTokens();

    [$exit, $output] = $world->call('mobile-push:fcm:topic-subscription', ['action' => 'subscribe', 'topic' => 'news', '--token' => [$one]]);

    expect($exit)->toBe(1)->and($output)->toContain('failed')->toContain('policy_safe_mode')
        ->and($world->w->transport->sentCount())->toBe(0);
});

it('refuses a wrong action, a missing topic, no installation and an invalid one without echoing it', function (): void {
    $world = OpsWorld::boot();
    [$one] = topicTokens();

    expect($world->call('mobile-push:fcm:topic-subscription', ['action' => 'frobnicate', '--token' => [$one]])[0])->toBe(1);
    [$exit, $output] = $world->call('mobile-push:fcm:topic-subscription', ['action' => 'subscribe', '--token' => [$one]]);
    expect($exit)->toBe(1)->and($output)->toContain('Say the topic');
    [$exit, $output] = $world->call('mobile-push:fcm:topic-subscription', ['action' => 'subscribe', 'topic' => 'news']);
    expect($exit)->toBe(1)->and($output)->toContain('No installation given');
    [$exit, $output] = $world->call('mobile-push:fcm:topic-subscription', ['action' => 'subscribe', 'topic' => 'news', '--token' => ['has spaces and \\ bad']]);
    expect($exit)->toBe(1)->and($output)->not->toContain('has spaces');
    expect($world->w->transport->sentCount())->toBe(0);
});
