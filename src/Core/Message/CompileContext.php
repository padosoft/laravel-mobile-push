<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Message;

use Padosoft\MobilePush\Core\Identity\BindingRef;

/**
 * Per-attempt inputs of a compilation. Expiry headers/TTL are derived from `$now` at EVERY attempt,
 * so a retry never extends the logical expiry of the message.
 */
final readonly class CompileContext
{
    public function __construct(
        public BindingRef $binding,
        public \DateTimeImmutable $now,
        /** Absolute logical expiry pinned when the effect was created (null = provider default). */
        public ?\DateTimeImmutable $expiresAt = null,
        /** FCM validate_only. */
        public bool $validateOnly = false,
        /** Provider correlation id chosen by us (APNs apns-id, a canonical lowercase UUID). */
        public ?string $messageId = null,
        /** Opaque receipt marker to embed in the payload, already counted against the size limit. */
        public ?string $receiptMarker = null,
    ) {}
}
