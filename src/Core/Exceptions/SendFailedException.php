<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Exceptions;

/** Thrown only by SendReport::throw() when at least one destination was not accepted. */
final class SendFailedException extends PushException {}
