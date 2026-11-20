<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Exceptions;

/** The message uses a capability the target platform does not offer (see the capability matrix). */
final class UnsupportedCapabilityException extends ValidationException {}
