<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Devices;

enum Ineligibility: string
{
    case RowMissing = 'row_missing';
    case StateNotActive = 'state_not_active';
    case GenerationChanged = 'generation_changed';
    case OwnerChanged = 'owner_changed';

    public function hint(): string
    {
        return match ($this) {
            self::RowMissing => 'The device registration no longer exists (pruned, deleted or from another scope).',
            self::StateNotActive => 'The device registration is quarantined or invalidated; the app must register it again.',
            self::GenerationChanged => 'The device was registered again after this push was queued; a newer generation is current.',
            self::OwnerChanged => 'The device now belongs to another owner (logout or shared phone); the push is not delivered.',
        };
    }
}
