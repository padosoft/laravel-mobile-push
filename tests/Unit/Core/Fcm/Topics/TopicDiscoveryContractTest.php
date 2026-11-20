<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Fcm\Topics\TopicSubscriptionClient;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Policy\EndpointCatalog;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Tests\Scenario\Fcm\Topics\ScriptedTokenProvider;

/**
 * Every request the client emits must exist in the pinned Discovery document (revision 20261112):
 * HTTP verb, path shape and query parameter names of projects.registrations.topicSubscriptions.
 */
function topicDiscovery(): array
{
    $document = json_decode((string) file_get_contents(__DIR__.'/../../../../Fixtures/fcm/discovery-v1.json'), true, 512, JSON_THROW_ON_ERROR);

    return $document['resources']['projects']['resources']['registrations']['resources']['topicSubscriptions'];
}

it('only emits verbs, paths and query parameters that the Discovery resource defines', function () {
    $resource = topicDiscovery();
    $transport = (new FakeTransport)->defaultTo(new Response(200, [], '{"topicSubscriptions":[{"topicName":"a"}]}'));
    $client = new TopicSubscriptionClient($transport, new ConnectionProfile('contract'), new EndpointCatalog('production'), 'proj-1', new ScriptedTokenProvider);
    $installation = FcmToken::of('reg-token:APA91bX');
    $topic = FcmTopic::of('news');

    $client->subscribe($installation, $topic);
    $client->get($installation, $topic);
    iterator_to_array($client->list($installation, 5));
    $client->patch($installation, $topic);
    $client->patch($installation, $topic, allowMissing: false);
    $client->unsubscribe($installation, $topic);

    $seen = [];

    foreach ($transport->requests() as $request) {
        $parts = parse_url($request->url);
        $path = ltrim($parts['path'], '/');
        parse_str($parts['query'] ?? '', $query);

        $matched = null;

        foreach ($resource['methods'] as $name => $method) {
            $pattern = '#^'.preg_replace('/\\\\\{[A-Za-z]+\\\\\}/', '[^/]+', preg_quote($method['flatPath'], '#')).'$#';

            if ($method['httpMethod'] === $request->method && preg_match($pattern, $path) === 1) {
                $matched = $name;

                break;
            }
        }

        expect($matched)->not->toBeNull("no Discovery method for {$request->method} {$path}");
        $seen[$matched] = true;

        $declared = array_keys(array_filter($resource['methods'][$matched]['parameters'], static fn (array $p): bool => $p['location'] === 'query'));

        foreach (array_keys($query) as $name) {
            expect($declared)->toContain($name);
        }

        // Required query parameters of the matched method must be present.
        if ($matched === 'create') {
            expect($query)->toHaveKey('topicName');
        }

        expect($parts['host'])->toBe('fcm.googleapis.com')
            ->and(str_starts_with($path, 'v1/projects/proj-1/registrations/'))->toBeTrue();
    }

    expect(array_keys($seen))->toEqualCanonicalizing(['create', 'get', 'list', 'patch', 'delete']);
});

it('declares its bodies as TopicSubscription fields only', function () {
    $document = json_decode((string) file_get_contents(__DIR__.'/../../../../Fixtures/fcm/discovery-v1.json'), true, 512, JSON_THROW_ON_ERROR);
    $fields = array_keys($document['schemas']['TopicSubscription']['properties']);
    $transport = (new FakeTransport)->defaultTo(new Response(200, [], '{}'));
    $client = new TopicSubscriptionClient($transport, new ConnectionProfile('contract'), new EndpointCatalog('production'), 'proj-1', new ScriptedTokenProvider);

    $client->subscribe(FcmToken::of('reg'), FcmTopic::of('news'));
    $client->patch(FcmToken::of('reg'), FcmTopic::of('news'));

    foreach ($transport->requests() as $request) {
        expect(array_diff(array_keys(json_decode($request->body, true, 512, JSON_THROW_ON_ERROR)), $fields))->toBe([]);
    }
});
