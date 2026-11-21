<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Console\ConsoleWorld as C;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

function openTheAuthBreaker(W $world): void
{
    $world->answer = static fn () => new Response(403, [], '{"reason":"InvalidProviderToken"}');
    $tokens = array_map(static fn (int $i): string => W::apnsToken('t'.$i), range(1, 6));
    W::all(MobilePush::toTokens(Platform::Ios, $tokens)->send(PushMessage::make('Hi')));
}

it('is all closed on a quiet connection', function () {
    W::install(C::both());

    [$code, $out] = C::run('mobile-push:circuit');

    expect($code)->toBe(0)->and($out)->toContain('Authentication breaker: closed')->toContain('Provider throttle: none');
});

it('shows an open authentication breaker after repeated credential failures, and resets it after confirmation', function () {
    $world = W::install(C::both());
    openTheAuthBreaker($world);

    [$code, $out] = C::run('mobile-push:circuit', ['connection' => 'ios']);
    expect($code)->toBe(1)->and($out)->toContain('Authentication breaker: open')->toContain('next probe after');

    $this->artisan('mobile-push:circuit', ['connection' => 'ios', '--reset' => true])
        ->expectsConfirmation('Close the authentication and destination-quality breakers of ios? Sends resume at once.', 'yes')
        ->assertExitCode(0);

    [$code, $out] = C::run('mobile-push:circuit', ['connection' => 'ios', '--json' => true]);
    expect($code)->toBe(0)->and(json_decode($out, true)['connections']['ios']['auth']['state'])->toBe('closed');
});

it('changes nothing when the reset is not confirmed or cannot be asked', function () {
    $world = W::install(C::both());
    openTheAuthBreaker($world);

    $this->artisan('mobile-push:circuit', ['connection' => 'ios', '--reset' => true])
        ->expectsConfirmation('Close the authentication and destination-quality breakers of ios? Sends resume at once.', 'no')
        ->assertExitCode(2);
    [$code, $out] = C::run('mobile-push:circuit', ['connection' => 'ios', '--reset' => true]);
    [, $state] = C::run('mobile-push:circuit', ['connection' => 'ios']);

    expect($code)->toBe(2)->and($out)->toContain('not interactive')->and($state)->toContain('Authentication breaker: open');

    [$forced] = C::run('mobile-push:circuit', ['connection' => 'ios', '--reset' => true, '--force' => true]);
    expect($forced)->toBe(0);
});

it('rejects an unknown connection', function () {
    W::install(C::both());

    [$code] = C::run('mobile-push:circuit', ['connection' => 'nope']);

    expect($code)->toBe(2);
});
