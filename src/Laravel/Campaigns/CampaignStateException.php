<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

/** A control that the campaign's current status does not allow (e.g. resume a completed campaign). */
final class CampaignStateException extends CampaignException {}
