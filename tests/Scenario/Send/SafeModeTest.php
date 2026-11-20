<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Policy\LiveSettings;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Story: a junior clones the project and sends a first push from a laptop, with production credentials in .env.
 */
it('simulates a first send on a laptop: no request, no token minted, every result Simulated with a hint', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $options = SendKit::live(applicationEnvironment: 'local', configuredMode: 'auto');

    $results = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('Hello', 'World'), SendKit::apnsTokens(3), $options));

    expect($transport->requests())->toBe([])
        ->and($kit->signers['KEY1111111']->signatures)->toBe(0)
        ->and($results)->toHaveCount(3)
        ->and(array_map(fn ($r) => $r->status, $results))->each->toBe(SendStatus::Simulated)
        ->and($results[0]->reason->code)->toBe(ReasonCode::Simulated)
        ->and($results[0]->reason->hint)->toContain('MOBILE_PUSH_MODE=live')
        ->and($results[0]->attempts)->toBe(0);
});

it('simulates by default when the caller does not say where it runs', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;

    [$result] = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('Hello', 'World'), [SendKit::apnsToken(1)]));

    expect($result->status)->toBe(SendStatus::Simulated)->and($transport->requests())->toBe([]);
});

it('still rejects an invalid destination in simulation, exactly as a live send would', function () {
    $kit = new SendKit;
    $tooLong = PushMessage::make('Offer', str_repeat('x', 3000));

    $results = SendKit::all($kit->sender(new FakeTransport)->send($kit->fcmDriver(), $tooLong, [SendKit::fcmToken(1), FcmTopic::of('deals')], SendKit::live(applicationEnvironment: 'local', configuredMode: 'auto')));

    expect($results[0]->status)->toBe(SendStatus::Simulated)
        ->and($results[1]->status)->toBe(SendStatus::Rejected)
        ->and($results[1]->reason->code)->toBe(ReasonCode::PayloadTooLarge);
});

/*
 * Story: staging may send for real, but only to the QA phone on the allowlist.
 */
it('sends only to the allowlisted device on staging; others and topics are skipped by the safe mode', function () {
    $kit = new SendKit;
    $transport = (new FakeTransport)->defaultTo(SendKit::fcmOk());
    $qa = SendKit::fcmToken(1);
    $stranger = SendKit::fcmToken(2);
    $topic = FcmTopic::of('everyone');
    $options = SendKit::live(applicationEnvironment: 'staging', configuredMode: 'live', live: new LiveSettings(allow: true, onlyTo: [$kit->index->index($qa, 'default')]));

    [$sent, $blocked, $topicResult] = SendKit::all($kit->sender($transport)->send($kit->fcmDriver(), PushMessage::make('QA', 'Check'), [$qa, $stranger, $topic], $options));

    expect($transport->requests())->toHaveCount(1)
        ->and(json_decode($transport->requests()[0]->body, true)['message']['token'])->toBe($qa->value())
        ->and($sent->status)->toBe(SendStatus::Accepted)
        ->and($blocked->status)->toBe(SendStatus::Skipped)
        ->and($blocked->reason->code)->toBe(ReasonCode::PolicySafeMode)
        ->and($blocked->reason->hint)->toContain('live.only_to')
        ->and($topicResult->status)->toBe(SendStatus::Skipped)
        ->and($topicResult->reason->code)->toBe(ReasonCode::PolicySafeMode)
        ->and($topicResult->reason->hint)->toContain('live.allow_topics');
});
