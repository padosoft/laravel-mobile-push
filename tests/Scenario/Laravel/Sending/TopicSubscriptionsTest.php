<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld as W;

/*
 * The official way to subscribe installations to an FCM topic: MobilePush::topicSubscriptions(), behind the same
 * safe-mode gate as a send. The bytes are those of the Discovery document (projects.registrations.topicSubscriptions).
 */
const TOPIC_TOKEN = 'fake-registration-token-topics-Zq9Zq9Zq9Zq9Zq9';

it('makes no request in simulate mode and says the subscription was simulated', function () {
    $world = W::install(['connections' => ['android' => W::fcm()], 'mode' => 'simulate']);

    $results = MobilePush::topicSubscriptions()->subscribeMany([FcmToken::of(TOPIC_TOKEN)], FcmTopic::of('news'));

    expect($results)->toHaveCount(1)
        ->and($results[0]->ok)->toBeFalse()
        ->and($results[0]->reason->code)->toBe(ReasonCode::Simulated)
        ->and($world->transport->sentCount())->toBe(0);
});

it('is blocked outside production while the connection does not allow topics, and unblocked by live.allow_topics', function () {
    $world = W::install(['connections' => ['android' => W::fcm()], 'mode' => 'live'], environment: 'staging');

    $blocked = MobilePush::topicSubscriptions('android')->subscribeMany([FcmToken::of(TOPIC_TOKEN)], FcmTopic::of('news'));

    expect($blocked[0]->ok)->toBeFalse()
        ->and($blocked[0]->reason->code)->toBe(ReasonCode::PolicySafeMode)
        ->and($world->pushRequests())->toBe([]);
});

it('lets the staging connection subscribe once live.allow_topics and the test token are allowed', function () {
    $world = W::install(['connections' => ['android' => W::fcm(extra: ['live' => ['allow' => true, 'allow_topics' => true, 'only_to' => [TOPIC_TOKEN]]])], 'mode' => 'live'], environment: 'staging');
    $world->answer = static fn () => new Response(200, [], (string) json_encode(['topicName' => 'news']));

    $allowed = MobilePush::topicSubscriptions('android')->subscribeMany([FcmToken::of(TOPIC_TOKEN)], FcmTopic::of('news'));

    expect($allowed[0]->ok)->toBeTrue()
        ->and($world->pushRequests())->toHaveCount(1);
});

it('sends the Discovery document request in live mode: POST .../registrations/{token}/topicSubscriptions?topicName=news', function () {
    $world = W::install(['connections' => ['android' => W::fcm()]]);
    $world->answer = static fn () => new Response(200, [], (string) json_encode(['topicName' => 'news']));

    $client = MobilePush::topicSubscriptions('android');
    $subscribed = $client->subscribe(FcmToken::of(TOPIC_TOKEN), FcmTopic::of('news'));
    $client->unsubscribe(FcmToken::of(TOPIC_TOKEN), FcmTopic::of('news'));

    [$post, $delete] = $world->pushRequests();
    $registration = 'https://fcm.googleapis.com/v1/projects/'.W::PROJECT.'/registrations/'.rawurlencode(TOPIC_TOKEN).'/topicSubscriptions';

    expect($subscribed->topicName)->toBe('news')
        ->and($post->method)->toBe('POST')
        ->and($post->url)->toBe($registration.'?topicName=news')
        ->and($post->body)->toBe('{}')
        ->and($post->headers['authorization'])->toBe('Bearer ya29.fake-access-token')
        ->and($delete->method)->toBe('DELETE')
        ->and($delete->url)->toBe($registration.'/news?allowMissing=true');
});

it('picks the only FCM connection, and refuses an APNs connection or an ambiguous choice naming the way out', function () {
    W::install(['connections' => ['ios' => W::apns(), 'android' => W::fcm(), 'android2' => W::fcm('other-project', 'push@other.iam.gserviceaccount.com')]]);

    expect(fn () => MobilePush::topicSubscriptions('ios'))->toThrow(ConfigurationException::class, 'is not an FCM connection')
        ->and(fn () => MobilePush::topicSubscriptions())->toThrow(ConfigurationException::class, 'Several fcm push connections');
});
