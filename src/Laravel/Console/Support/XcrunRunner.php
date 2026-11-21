<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** Pushes a payload file to the iOS Simulator with `xcrun simctl push`. Bind a fake in tests. */
class XcrunRunner
{
    public function available(): bool
    {
        return PHP_OS_FAMILY === 'Darwin' && (new ExecutableFinder)->find('xcrun') !== null;
    }

    /** @return array{0: bool, 1: string} success and the command output */
    public function push(string $device, string $file): array
    {
        $process = new Process(['xcrun', 'simctl', 'push', $device, $file]);
        $process->setTimeout(30);
        $process->run();

        return [$process->isSuccessful(), trim($process->getOutput().$process->getErrorOutput())];
    }
}
