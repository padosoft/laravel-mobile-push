<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Receipts;

use Padosoft\MobilePush\Core\Ledger\ClientEvent;

/** One report from the app. The marker is a secret: it is hidden from dumps. */
final readonly class ReceiptReport
{
    public function __construct(
        #[\SensitiveParameter] public string $marker,
        public ClientEvent $event,
        public ?\DateTimeImmutable $at,
    ) {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['event' => $this->event->value];
    }
}
