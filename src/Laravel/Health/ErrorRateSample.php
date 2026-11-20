<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

/** Samples and errors of one binding over a window (see ErrorRate for what counts). */
final readonly class ErrorRateSample
{
    /** @param  array{provider: string, connection: string, environment: string}  $binding */
    public function __construct(
        public array $binding,
        public int $sample,
        public int $errors,
    ) {}

    public function rate(int $minSample): ?float
    {
        return ErrorRate::rate($this->sample, $this->errors, $minSample);
    }
}
