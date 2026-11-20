<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\LiveActivity\LiveActivityMessage;
use Padosoft\MobilePush\Core\Apns\Message\Alert;
use Padosoft\MobilePush\Core\Apns\Message\ApnsMessage;
use Padosoft\MobilePush\Core\Apns\Priority;
use Padosoft\MobilePush\Core\Apns\PushType;
use Padosoft\MobilePush\Core\Message\PushMessage;

it('creates an overlay that records nothing until something is set', function () {
    $overlay = ApnsMessage::overlay();

    expect($overlay->pushType)->toBeNull()->and($overlay->priority)->toBeNull()->and($overlay->alert)->toBeNull()
        ->and($overlay->badge)->toBeNull()->and($overlay->custom)->toBe([])->and($overlay->truncate)->toBeFalse();
});

it('is what PushMessage::apns() fills', function () {
    $message = PushMessage::make('x')->apns(fn (ApnsMessage $apns) => $apns->badge(0)->collapseId('c'));

    expect($message->apnsOverlay)->toBeInstanceOf(ApnsMessage::class)
        ->and($message->apnsOverlay?->badge)->toBe(0)
        ->and($message->apnsOverlay?->pushType)->toBeNull();
});

it('merges overlays field by field without modifying either side', function () {
    $base = ApnsMessage::alert('Base title', 'Base body')->custom('a', 1)->badge(1);
    $overlay = ApnsMessage::overlay()->title('New title')->custom('b', 2);
    $baseBefore = serialize($base);
    $overlayBefore = serialize($overlay);

    $merged = $base->overlaidWith($overlay);

    expect($merged->alert)->toEqual(Alert::make('New title', 'Base body'))
        ->and($merged->custom)->toBe(['a' => 1, 'b' => 2])
        ->and($merged->badge)->toBe(1)
        ->and(serialize($base))->toBe($baseBefore)->and(serialize($overlay))->toBe($overlayBefore);
});

it('survives serialization', function () {
    $message = ApnsMessage::background()->custom('k', ['v' => 1])->priority(Priority::Normal);
    $restored = unserialize(serialize($message));

    expect($restored)->toEqual($message)->and($restored->pushType)->toBe(PushType::Background);
});

it('serializes Live Activity messages too', function () {
    $message = LiveActivityMessage::update(['a' => 1])->staleAt(new DateTimeImmutable('@100'))->relevanceScore(3.5);

    expect(unserialize(serialize($message)))->toEqual($message);
});

it('keeps Alert immutable', function () {
    $alert = Alert::make('t', 'b');
    $changed = $alert->withTitle('other');

    expect($alert->title)->toBe('t')->and($changed->title)->toBe('other')->and($changed->body)->toBe('b');
});
