<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Exceptions;

/** The same effect key was reused with a different payload fingerprint. */
final class IdempotencyConflictException extends ValidationException {}
