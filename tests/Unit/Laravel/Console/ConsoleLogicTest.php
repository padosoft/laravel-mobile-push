<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Console\Support\ConsoleInputException;
use Padosoft\MobilePush\Laravel\Console\Support\EnvChecklist;
use Padosoft\MobilePush\Laravel\Console\Support\HorizonBlock;
use Padosoft\MobilePush\Laravel\Console\Support\LocalCompiler;
use Padosoft\MobilePush\Laravel\Console\Support\PlatformProposal;
use Padosoft\MobilePush\Laravel\Console\Support\SendExit;
use Padosoft\MobilePush\Laravel\Console\Support\TokenInput;
use Padosoft\MobilePush\Laravel\Queue\QueueSettings;

it('maps result counts to the documented exit code', function (array $counts, int $exit) {
    expect(SendExit::fromCounts($counts))->toBe($exit);
})->with([
    'all accepted' => [['accepted' => 3], 0],
    'validated only' => [['validated' => 1], 0],
    'one rejected' => [['accepted' => 2, 'rejected' => 1], 1],
    'all failed' => [['failed' => 2], 1],
    'skipped by safe mode' => [['skipped' => 1], 1],
    'deferred' => [['accepted' => 1, 'deferred' => 1], 1],
    'simulated' => [['simulated' => 2], 3],
    'unknown beats rejected' => [['accepted' => 1, 'rejected' => 1, 'outcome_unknown' => 1], 4],
    'nothing at all' => [[], 2],
]);

it('proposes a platform only for an unambiguous list of tokens', function (array $tokens, ?Platform $expected) {
    expect(PlatformProposal::forTokens($tokens))->toBe($expected);
})->with([
    'hex tokens look like iOS' => [[str_repeat('ab', 32), str_repeat('0f', 40)], Platform::Ios],
    'registration tokens look like Android' => [['dXNlcjpmY206QUJD:APA91bH-x', 'another:token_value'], Platform::Android],
    'a mixed list gets no proposal' => [[str_repeat('ab', 32), 'dXNlcjpmY206QUJD:APA91bH-x'], null],
    'an odd-length hex string is not an APNs token' => [[str_repeat('a', 65)], Platform::Android],
    'nothing' => [[], null],
]);

it('collects tokens from arguments, a file and stdin without duplicates, comments or blanks', function () {
    $file = tempnam(sys_get_temp_dir(), 'tok');
    file_put_contents($file, "one\n# comment\n\ntwo\r\n");

    expect(TokenInput::collect(['zero', 'one'], $file, "two\nthree\n"))->toBe(['zero', 'one', 'two', 'three']);
    @unlink($file);
    expect(fn () => TokenInput::collect([], '/nonexistent/tokens.txt', null))->toThrow(ConsoleInputException::class);
});

it('renders the env checklist for the chosen profile and modules', function () {
    $lines = implode("\n", EnvChecklist::lines(['devices', 'logs'], 'durable'));

    expect($lines)->toContain('MOBILE_PUSH_APNS_TEAM_ID=')
        ->toContain('MOBILE_PUSH_DELIVERY_PROFILE=durable')
        ->toContain('MOBILE_PUSH_DEVICES_ENABLED=true')
        ->toContain('MOBILE_PUSH_LOG_SINKS=database')
        ->not->toContain('LIVE_ACTIVITIES');
});

it('derives the worker timeout and retry_after from the job timeout with the margins the doctor demands', function () {
    $settings = new QueueSettings(timeout: 120);

    expect(HorizonBlock::workerTimeout($settings))->toBe(132)
        ->and(HorizonBlock::minimumRetryAfter($settings))->toBe(146)
        ->and(HorizonBlock::supervisors($settings))->toContain("'timeout' => 132")->toContain("'queue' => ['push-bulk']")->toContain("'balance' => 'auto'")
        ->and(HorizonBlock::queueWork($settings, 'redis')[0])->toBe('php artisan queue:work redis --queue=push --tries=1 --timeout=132 --memory=256');
});

it('lists the capabilities that need app code and those the platform lacks', function () {
    $compiler = app(LocalCompiler::class);
    $message = PushMessage::make('Hi', 'There')->image('https://example.com/a.png')->deepLink('app://orders/1');

    $ios = $compiler->compile($message, Platform::Ios, null, null);

    expect(implode(' ', $ios->appCodeNotes))->toContain('`image`')->toContain('`deep_link`')
        ->and($ios->unsupported)->toBe([])
        ->and($ios->destinationLabel)->toContain('placeholder')
        ->and($ios->compiled->payloadBytes)->toBeLessThan($ios->compiled->limitBytes);
});
