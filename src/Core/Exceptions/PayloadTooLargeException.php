<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Exceptions;

/** The encoded payload exceeds the limit for this driver, destination kind and push type. */
final class PayloadTooLargeException extends ValidationException {}
