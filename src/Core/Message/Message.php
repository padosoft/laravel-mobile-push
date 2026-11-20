<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Message;

/**
 * Marker for everything that can be sent: the unified PushMessage and the native provider messages.
 * Implementations must be plain serializable objects (no closures, no clients, no secrets).
 */
interface Message {}
