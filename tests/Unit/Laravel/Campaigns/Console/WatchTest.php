<?php

declare(strict_types=1);

use Padosoft\MobilePush\Laravel\Campaigns\Console\Support\Sleeper;
use Padosoft\MobilePush\Laravel\Campaigns\Console\Support\Watch;

function recordingSleeper(): Sleeper
{
    return new class implements Sleeper
    {
        /** @var list<int> */
        public array $slept = [];

        public function sleep(int $seconds): void
        {
            $this->slept[] = $seconds;
        }
    };
}

it('renders until the step says stop, sleeping the given seconds between renders and not after the last', function (): void {
    $sleeper = recordingSleeper();
    $n = 0;

    $renders = Watch::run(function () use (&$n): bool {
        return ++$n === 4;
    }, $sleeper, 7);

    expect($renders)->toBe(4)->and($sleeper->slept)->toBe([7, 7, 7]);
});

it('a campaign already terminal is rendered once and never slept on', function (): void {
    $sleeper = recordingSleeper();

    expect(Watch::run(static fn (): bool => true, $sleeper, 5))->toBe(1)->and($sleeper->slept)->toBe([]);
});

it('a cap on renders ends a watch that never finishes', function (): void {
    $sleeper = recordingSleeper();

    expect(Watch::run(static fn (): bool => false, $sleeper, 1, maxRenders: 3))->toBe(3)->and($sleeper->slept)->toBe([1, 1, 1]);
});
