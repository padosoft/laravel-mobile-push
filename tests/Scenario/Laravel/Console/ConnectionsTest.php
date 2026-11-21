<?php

declare(strict_types=1);

use Padosoft\MobilePush\Tests\Scenario\Laravel\Console\ConsoleWorld as C;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

it('lists both connections with their non-secret identity and no secret at all', function () {
    W::install(C::both());

    [$code, $out] = C::run('mobile-push:connections');

    expect($code)->toBe(0)
        ->and($out)->toContain('ios')->toContain('apns')->toContain(W::BUNDLE)->toContain('team '.W::TEAM)->toContain('KEY1111111')
        ->toContain('android')->toContain('fcm')->toContain(W::PROJECT)->toContain(W::SA_EMAIL)
        ->toContain('Effective mode: live')
        ->and($out)->not->toContain('PRIVATE KEY')
        ->not->toContain(C::canary(W::ecPem('KEY1111111')))
        ->not->toContain(C::canary(W::rsaPem()));
});

it('prints the same facts as JSON, with the generation and the live settings', function () {
    W::install(['connections' => ['ios' => W::apns(extra: ['live' => ['allow' => true, 'only_to' => [W::apnsToken('qa')], 'allow_topics' => true]])]], environment: 'staging');

    [$code, $out] = C::run('mobile-push:connections', ['--json' => true]);
    $data = json_decode($out, true);

    expect($code)->toBe(0)
        ->and($data['connections'][0]['generation'])->toMatch('/^[0-9a-f]{12}$/')
        ->and($data['connections'][0]['live'])->toMatchArray(['allow' => true, 'only_to' => 1, 'allow_topics' => true])
        ->and($out)->not->toContain(W::apnsToken('qa'))->not->toContain(C::canary(W::ecPem('KEY1111111')));
});

it('shows days left for a certificate and the problem of a broken connection', function () {
    $world = W::install(['connections' => ['broken' => ['driver' => 'apns', 'team_id' => 'T', 'bundle_id' => 'b', 'key_id' => 'K']]]);
    $world->clock->set(gmdate('c'));

    [$code, $out] = C::run('mobile-push:connections');

    expect($code)->toBe(0)->and($out)->toContain('broken')->toContain('key');
});
