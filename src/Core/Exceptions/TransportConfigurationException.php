<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Exceptions;

/** The transport cannot work in this runtime (e.g. HTTP/2 or mTLS blob support missing). */
final class TransportConfigurationException extends ConfigurationException {}
