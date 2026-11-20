<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityEvent;
use Padosoft\MobilePush\Core\Apns\Message\Alert;
use Padosoft\MobilePush\Core\Apns\Priority;
use Padosoft\MobilePush\Laravel\LiveActivities\ActivityTicket;
use Padosoft\MobilePush\Laravel\LiveActivities\Transition\TransitionPayload;

it('survives the trip through a queued ticket (JSON) without losing a field, numeric state keys included', function (): void {
    $alert = new Alert(title: 'Goal', body: 'Rossi 89\'', locKey: 'GOAL', locArgs: ['Rossi']);
    $payload = new TransitionPayload(LiveActivityEvent::End, ['7' => 'x', 'eta' => 0, 'pi' => 1.0], Priority::Normal, 1_763_632_900, 1_763_633_000, 0.5, TransitionPayload::alertArray($alert), 'default');
    $ticket = new ActivityTicket(ActivityTicket::TARGET_CHANNEL, '01jd0000000000000000000000', 12, 30, $payload);

    $back = ActivityTicket::fromArray(json_decode(json_encode($ticket->toArray(), JSON_PRESERVE_ZERO_FRACTION), true));

    expect($back)->toEqual($ticket);
    $message = $back->payload->toMessage(channelStoresNoMessages: true);
    expect($message->event)->toBe(LiveActivityEvent::End)
        ->and($message->contentState)->toBe(['7' => 'x', 'eta' => 0, 'pi' => 1.0])
        ->and($message->priority)->toBe(Priority::Normal)
        ->and($message->dismissAt?->getTimestamp())->toBe(1_763_633_000)
        ->and($message->staleAt?->getTimestamp())->toBe(1_763_632_900)
        ->and($message->alert)->toEqual($alert)
        ->and($message->sound?->name)->toBe('default')
        ->and($message->channelStoresNoMessages)->toBeTrue();
});
