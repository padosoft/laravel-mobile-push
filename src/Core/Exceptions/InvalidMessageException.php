<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Exceptions;

/** The message is invalid for the target driver (conflicting options, bad UTF-8, reserved keys). */
final class InvalidMessageException extends ValidationException {}
