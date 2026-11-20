<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Exceptions;

/** No push connection (app binding) resolves for the requested identity. */
final class UnknownConnectionException extends ConfigurationException {}
