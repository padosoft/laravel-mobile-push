<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Exceptions;

/**
 * Base class of every exception thrown by the package.
 *
 * Exceptions are reserved for globally invalid input or configuration detected BEFORE the network.
 * A failure of a single destination is a SendResult, never an exception.
 */
class PushException extends \RuntimeException {}
