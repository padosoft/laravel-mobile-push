<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Privacy;

use Padosoft\MobilePush\Core\Exceptions\PushException;

/** Raised by the privacy primitives. Messages never contain plaintext, ciphertext or key material. */
final class PrivacyException extends PushException {}
