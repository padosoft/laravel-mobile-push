<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

/**
 * The durable effects ledger (durable profile only; the light profile never touches it).
 * See docs/contracts/ledger.md.
 */
interface Ledger extends DeliveryStore, EffectStore {}
