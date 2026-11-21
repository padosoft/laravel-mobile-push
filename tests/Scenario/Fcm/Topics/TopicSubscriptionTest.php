<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Fcm\Topics\TopicApiException;
use Padosoft\MobilePush\Core\Fcm\Topics\TopicSubscription;
use Padosoft\MobilePush\Core\Fcm\Topics\TopicSubscriptionClient;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\FcmFid;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Policy\EndpointCatalog;
use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Core\Policy\SafeModeDecision;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Tests\Scenario\Fcm\Topics\ScriptedTokenProvider;

const TOPIC_SECRET_TOKEN = 'cAbC123:APA91bSecretRegistrationToken-xyz';
const TOPIC_BASE = 'https://fcm.googleapis.com/v1/projects/my-project/registrations/';

function topicClient(FakeTransport $transport, ?ScriptedTokenProvider $tokens = null, ?Closure $gate = null): TopicSubscriptionClient
{
    return new TopicSubscriptionClient($transport, new ConnectionProfile('fcm-topics-test'), new EndpointCatalog('production'), 'my-project', $tokens ?? new ScriptedTokenProvider, $gate);
}

function googleError(int $code, string $status, string $message = 'boom', ?string $errorCode = null): string
{
    $error = ['code' => $code, 'message' => $message, 'status' => $status];

    if ($errorCode !== null) {
        $error['details'] = [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => $errorCode]];
    }

    return json_encode(['error' => $error], JSON_THROW_ON_ERROR);
}

it('subscribes, reads, lists two pages and unsubscribes with the Discovery shapes', function () {
    $transport = (new FakeTransport)->push(
        new Response(200, [], '{"name":"projects/my-project/registrations/r/topicSubscriptions/news","topicName":"news","createTime":"2026-11-20T10:00:00Z"}'),
        new Response(200, [], '{"topicName":"news","createTime":"2026-11-20T10:00:00Z"}'),
        new Response(200, [], '{"topicSubscriptions":[{"topicName":"a"},{"topicName":"b"}],"nextPageToken":"tok/2=="}'),
        new Response(200, [], '{"topicSubscriptions":[{"name":"projects/p/registrations/r/topicSubscriptions/c%25d"}]}'),
        new Response(200, [], '{}'),
    );
    $client = topicClient($transport);
    $installation = FcmToken::of(TOPIC_SECRET_TOKEN);
    $topic = FcmTopic::of('/topics/news');

    $created = $client->subscribe($installation, $topic);
    $read = $client->get($installation, $topic);
    $listed = iterator_to_array($client->list($installation, 2), false);
    $client->unsubscribe($installation, $topic);

    expect($created->topicName)->toBe('news')
        ->and($created->createTime?->format('c'))->toBe('2026-11-20T10:00:00+00:00')
        ->and($created->alreadyExisted)->toBeFalse()
        ->and($read->topicName)->toBe('news')
        ->and(array_map(static fn (TopicSubscription $s) => $s->topicName, $listed))->toBe(['a', 'b', 'c%d']);

    $registration = TOPIC_BASE.rawurlencode(TOPIC_SECRET_TOKEN).'/topicSubscriptions';
    [$post, $get, $page1, $page2, $delete] = $transport->requests();

    expect($post->method)->toBe('POST')
        ->and($post->url)->toBe($registration.'?topicName=news')
        ->and($post->body)->toBe('{}')
        ->and($post->headers['authorization'])->toBe('Bearer ya29.fake-access-1')
        ->and($get->method)->toBe('GET')
        ->and($get->url)->toBe($registration.'/news')
        ->and($page1->url)->toBe($registration.'?pageSize=2')
        ->and($page2->url)->toBe($registration.'?pageSize=2&pageToken=tok%2F2%3D%3D')
        ->and($delete->method)->toBe('DELETE')
        ->and($delete->url)->toBe($registration.'/news?allowMissing=true');
});

it('addresses an installation by FID and reads lazily', function () {
    $transport = (new FakeTransport)->defaultTo(new Response(200, [], '{"topicSubscriptions":[{"topicName":"a"}]}'));
    $generator = topicClient($transport)->list(FcmFid::of('dGhpcy1pcy1hbi1maWQtMT'));

    expect($transport->requests())->toBe([]);

    iterator_to_array($generator);

    expect($transport->requests()[0]->url)->toBe(TOPIC_BASE.'dGhpcy1pcy1hbi1maWQtMT/topicSubscriptions');
});

it('patches as an upsert with allowMissing', function () {
    $transport = (new FakeTransport)->push(new Response(200, [], '{"topicName":"news"}'));

    topicClient($transport)->patch(FcmFid::of('dGhpcy1pcy1hbi1maWQtMT'), FcmTopic::of('news'));

    expect($transport->requests()[0]->method)->toBe('PATCH')
        ->and($transport->requests()[0]->url)->toBe(TOPIC_BASE.'dGhpcy1pcy1hbi1maWQtMT/topicSubscriptions/news?allowMissing=true');
});

it('treats an already subscribed installation as success', function () {
    $transport = (new FakeTransport)->push(new Response(409, [], googleError(409, 'ALREADY_EXISTS')));

    $result = topicClient($transport)->subscribe(FcmToken::of(TOPIC_SECRET_TOKEN), FcmTopic::of('news'));

    expect($result->alreadyExisted)->toBeTrue()->and($result->topicName)->toBe('news');
});

it('treats an already unsubscribed installation as success because allowMissing is always sent', function () {
    $transport = (new FakeTransport)->push(new Response(200, [], '{}'));

    topicClient($transport)->unsubscribe(FcmToken::of(TOPIC_SECRET_TOKEN), FcmTopic::of('news'));

    expect($transport->requests()[0]->url)->toEndWith('?allowMissing=true');
});

it('reports a missing subscription on get as a typed not-found error', function () {
    $transport = (new FakeTransport)->push(new Response(404, [], googleError(404, 'NOT_FOUND')));

    try {
        topicClient($transport)->get(FcmToken::of(TOPIC_SECRET_TOKEN), FcmTopic::of('news'));
    } catch (TopicApiException $e) {
        expect($e->isNotFound())->toBeTrue()->and($e->reason->rawReason)->toBe('NOT_FOUND');

        return;
    }

    throw new LogicException('expected an exception');
});

it('refreshes the token once and retries once after a 401', function () {
    $tokens = new ScriptedTokenProvider;
    $transport = (new FakeTransport)->push(
        new Response(401, [], googleError(401, 'UNAUTHENTICATED')),
        new Response(200, [], '{"topicName":"news"}'),
    );

    topicClient($transport, $tokens)->subscribe(FcmToken::of(TOPIC_SECRET_TOKEN), FcmTopic::of('news'));

    expect($tokens->refreshes)->toBe(1)
        ->and($transport->requests())->toHaveCount(2)
        ->and($transport->requests()[0]->headers['authorization'])->toBe('Bearer ya29.fake-access-1')
        ->and($transport->requests()[1]->headers['authorization'])->toBe('Bearer ya29.fake-access-2');
});

it('gives up after one retry when the refreshed token is also refused', function () {
    $tokens = new ScriptedTokenProvider;
    $transport = (new FakeTransport)->defaultTo(new Response(401, [], googleError(401, 'UNAUTHENTICATED')));

    try {
        topicClient($transport, $tokens)->unsubscribe(FcmToken::of(TOPIC_SECRET_TOKEN), FcmTopic::of('news'));
    } catch (TopicApiException $e) {
        expect($e->reason->code)->toBe(ReasonCode::AuthTokenInvalid)
            ->and($tokens->refreshes)->toBe(1)
            ->and($transport->requests())->toHaveCount(2);

        return;
    }

    throw new LogicException('expected an exception');
});

it('keeps the other four results when one installation of five fails', function () {
    $installations = array_map(static fn (int $i) => FcmToken::of("token-$i:APA91bSecret$i"), range(0, 4));
    $transport = (new FakeTransport)->respondUsing(static function (Request $request): Response {
        return match (true) {
            str_contains($request->url, 'token-1') => new Response(404, [], googleError(404, 'NOT_FOUND', 'Requested entity token-1:APA91bSecret1 was not found', 'UNREGISTERED')),
            str_contains($request->url, 'token-3') => new Response(409, [], googleError(409, 'ALREADY_EXISTS')),
            default => new Response(200, [], '{"topicName":"news"}'),
        };
    });

    $results = topicClient($transport)->subscribeMany($installations, FcmTopic::of('news'));

    expect($results)->toHaveCount(5)
        ->and(array_map(static fn ($r) => $r->ok, $results))->toBe([true, false, true, true, true])
        ->and($results[1]->reason->code)->toBe(ReasonCode::DestinationUnregistered)
        ->and($results[1]->reason->rawReason)->toBe('UNREGISTERED')
        ->and($results[3]->alreadyInState)->toBeTrue()
        ->and($transport->requests())->toHaveCount(5)
        ->and(var_export($results, true))->not->toContain('APA91b');
});

it('reports a transport failure of one installation without hiding the others', function () {
    $installations = [FcmToken::of('tok-a:APA91bA'), FcmToken::of('tok-b:APA91bB')];
    $transport = (new FakeTransport)->push(
        new TransportFailure(TransportEvidence::Unknown, 28, 'timeout'),
        new Response(200, [], '{}'),
    );

    $results = topicClient($transport)->unsubscribeMany($installations, FcmTopic::of('news'));

    expect($results[0]->ok)->toBeFalse()
        ->and($results[0]->reason->code)->toBe(ReasonCode::TransportUnknown)
        ->and($results[1]->ok)->toBeTrue()
        ->and($transport->requests()[0]->method)->toBe('DELETE');
});

it('refreshes the token once for the whole batch and retries only the rejected ones', function () {
    $tokens = new ScriptedTokenProvider;
    $answers = [];
    $transport = (new FakeTransport)->respondUsing(static function (Request $request) use (&$answers): Response {
        $answers[] = $request->url.'|'.$request->headers['authorization'];

        return str_contains($request->headers['authorization'], 'access-1') && str_contains($request->url, 'tok-b')
            ? new Response(401, [], googleError(401, 'UNAUTHENTICATED'))
            : new Response(200, [], '{}');
    });

    $results = topicClient($transport, $tokens)->subscribeMany([FcmToken::of('tok-a'), FcmToken::of('tok-b'), FcmToken::of('tok-c')], FcmTopic::of('news'));

    expect(array_map(static fn ($r) => $r->ok, $results))->toBe([true, true, true])
        ->and($tokens->refreshes)->toBe(1)
        ->and($transport->requests())->toHaveCount(4);
});

afterEach(function (): void {
    ini_restore('zend.exception_ignore_args');   // one test below turns it off: never leave it off for the rest of the run
});

it('never leaks the registration id or the bearer through exceptions', function () {
    ini_set('zend.exception_ignore_args', '0');
    $tokens = new ScriptedTokenProvider;
    $transport = (new FakeTransport)->defaultTo(new Response(400, [], googleError(400, 'INVALID_ARGUMENT', 'Invalid registration token '.TOPIC_SECRET_TOKEN.' and Bearer ya29.fake-access-1')));

    try {
        topicClient($transport, $tokens)->subscribe(FcmToken::of(TOPIC_SECRET_TOKEN), FcmTopic::of('news'));
    } catch (TopicApiException $e) {
        // What the library put in the exception: its message, its own properties and the arguments of ITS frames.
        // The frames of the test runner are left out on purpose: with exception_ignore_args off they hold the whole
        // suite (datasets of other tests included), which is not something this client can leak or protect.
        $ownFrames = array_values(array_filter($e->getTrace(), static fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'Padosoft\\MobilePush\\')
            && ! str_starts_with($frame['class'] ?? '', 'Padosoft\\MobilePush\\Tests\\')));
        expect($ownFrames)->not->toBeEmpty();
        $dump = var_export($ownFrames, true).$e->getMessage().var_export($e->getPrevious(), true).var_export($e->status, true).serialize($e->reason);

        foreach ([TOPIC_SECRET_TOKEN, 'APA91b', rawurlencode(TOPIC_SECRET_TOKEN), 'ya29.fake-access'] as $secret) {
            expect($dump)->not->toContain($secret);
        }

        expect($e->reason->code)->toBe(ReasonCode::RequestInvalid)->and($e->status)->toBe(400);

        return;
    }

    throw new LogicException('expected an exception');
});

it('leaves the transport untouched in simulate mode, for single calls and batches', function () {
    $transport = new FakeTransport;
    $seen = [];
    $client = topicClient($transport, null, static function (OperationKind $op, Destination $d) use (&$seen): SafeModeDecision {
        $seen[] = $op;

        return SafeModeDecision::simulate('Simulation mode: no request was sent.');
    });
    $installation = FcmToken::of(TOPIC_SECRET_TOKEN);
    $topic = FcmTopic::of('news');

    foreach ([
        fn () => $client->subscribe($installation, $topic),
        fn () => $client->get($installation, $topic),
        fn () => $client->patch($installation, $topic),
        fn () => $client->unsubscribe($installation, $topic),
        fn () => iterator_to_array($client->list($installation)),
    ] as $operation) {
        try {
            $operation();
            $code = null;
        } catch (TopicApiException $e) {
            $code = $e->reason->code;
        }

        expect($code)->toBe(ReasonCode::Simulated);
    }

    $batch = $client->subscribeMany([$installation, FcmToken::of('another')], $topic);

    expect($batch[0]->ok)->toBeFalse()
        ->and($batch[1]->reason->code)->toBe(ReasonCode::Simulated)
        ->and($seen)->toContain(OperationKind::TopicSubscription)
        ->and($transport->requests())->toBe([]);
});
