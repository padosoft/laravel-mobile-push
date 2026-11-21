<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Console\Support;

/**
 * The refresh loop of `campaign:status --watch=N`: render, stop when the campaign needs nothing more from the
 * watcher, otherwise sleep and render again. The sleeper is injected so the loop can be tested without waiting.
 */
final class Watch
{
    /**
     * @param  \Closure(): bool  $step  renders once; returns true when the watcher should stop (terminal campaign)
     * @return int the number of renders
     */
    public static function run(\Closure $step, Sleeper $sleeper, int $seconds, int $maxRenders = PHP_INT_MAX): int
    {
        $renders = 0;
        while ($renders < $maxRenders) {
            $renders++;
            if ($step()) {
                break;
            }
            $sleeper->sleep($seconds);
        }

        return $renders;
    }
}
