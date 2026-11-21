<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns;

/** What can happen to a campaign. Operator controls are Start, Pause, Resume and Cancel; the rest is the planner's. */
enum CampaignControl: string
{
    case Start = 'start';
    case Pause = 'pause';
    case Resume = 'resume';
    case Cancel = 'cancel';
    case FinishPlanning = 'finish_planning';
    case Complete = 'complete';
    case Fail = 'fail';
}
