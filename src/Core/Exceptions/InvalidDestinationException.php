<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Exceptions;

/** The destination is malformed or does not belong to the connection it is sent through. */
final class InvalidDestinationException extends ValidationException {}
