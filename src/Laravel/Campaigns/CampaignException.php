<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

use Padosoft\MobilePush\Core\Exceptions\PushException;

/** A campaign cannot be planned as stored (corrupt cursor, unknown audience class, erased message...). */
class CampaignException extends PushException {}
