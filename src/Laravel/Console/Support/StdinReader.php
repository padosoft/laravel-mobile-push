<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

/** Reads standard input once. Bind another instance in the container to feed a test. */
final class StdinReader
{
    /** @param  (\Closure(): string)|null  $source */
    public function __construct(private readonly ?\Closure $source = null) {}

    public function contents(): string
    {
        if ($this->source !== null) {
            return ($this->source)();
        }
        $data = stream_get_contents(STDIN);

        return $data === false ? '' : $data;
    }
}
