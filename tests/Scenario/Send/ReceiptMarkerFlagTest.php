<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Story: the ledger needs to know which results carried a receipt marker, to count "can report back" coverage.
 * The flag says a marker was really embedded in the payload, not merely requested.
 */

it('flags the results whose payload carried a marker, and only those', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->defaultTo(new Response(200));
    $callback = static fn (Destination $d, string $messageId): ?string => 'MARKER-'.substr($messageId, 0, 8);

    $results = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('Hi', 'There'), SendKit::apnsTokens(2), SendKit::live(receiptMarker: $callback)));

    expect($results)->toHaveCount(2);
    foreach ($results as $i => $result) {
        expect($result->status)->toBe(SendStatus::Accepted)
            ->and($result->meta['receipt_marker'] ?? null)->toBe('1')
            ->and($transport->requests()[$i]->body)->toContain('MARKER-');
    }
});

it('does not flag results sent without a callback', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->defaultTo(new Response(200));

    $results = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('Hi', 'There'), SendKit::apnsTokens(2), SendKit::live()));

    expect($results[0]->status)->toBe(SendStatus::Accepted)
        ->and($results[0]->meta)->not->toHaveKey('receipt_marker')
        ->and($results[1]->meta)->not->toHaveKey('receipt_marker');
});

it('does not flag a topic when the callback declines it, and flags the device token next to it', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->defaultTo(SendKit::fcmOk());
    $callback = static fn (Destination $d, string $messageId): ?string => $d instanceof FcmTopic ? null : 'MARKER-device';

    [$device, $topic] = SendKit::all($kit->sender($transport)->send($kit->fcmDriver(), PushMessage::make('Hi', 'There'), [SendKit::fcmToken(1), FcmTopic::of('deals')], SendKit::live(receiptMarker: $callback)));

    expect($device->status)->toBe(SendStatus::Accepted)
        ->and($device->meta['receipt_marker'] ?? null)->toBe('1')
        ->and($topic->status)->toBe(SendStatus::Accepted)
        ->and($topic->meta)->not->toHaveKey('receipt_marker')
        ->and($transport->requests()[1]->body)->not->toContain('MARKER-');
});

it('does not flag a simulated send: no marker is even requested', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $asked = 0;
    $callback = static function (Destination $d, string $messageId) use (&$asked): ?string {
        $asked++;

        return 'MARKER-x';
    };

    $results = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('Hi', 'There'), SendKit::apnsTokens(2), SendKit::live(applicationEnvironment: 'local', configuredMode: 'auto', receiptMarker: $callback)));

    expect($transport->requests())->toBe([])
        ->and($asked)->toBe(0);
    foreach ($results as $result) {
        expect($result->status)->toBe(SendStatus::Simulated)->and($result->meta)->not->toHaveKey('receipt_marker');
    }
});
