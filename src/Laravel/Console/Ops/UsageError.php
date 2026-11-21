<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Ops;

/** A command line the operator got wrong, or a refusal with its remedy: printed, exit code 1, nothing was done. */
final class UsageError extends \RuntimeException {}
