<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

/** Where an effect comes from, when it comes from a Laravel notification (or any notifiable owner). */
final readonly class EffectOrigin
{
    public function __construct(
        public ?string $notifiableType = null,
        public ?string $notifiableId = null,
        public ?string $notificationClass = null,
    ) {}
}
