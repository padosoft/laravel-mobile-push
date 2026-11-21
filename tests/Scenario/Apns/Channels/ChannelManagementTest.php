<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Apns\Channels\Channel;
use Padosoft\MobilePush\Core\Apns\Channels\ChannelApiException;
use Padosoft\MobilePush\Core\Apns\Channels\ChannelClient;
use Padosoft\MobilePush\Core\Apns\Channels\ChannelOutcomeUnknownException;
use Padosoft\MobilePush\Core\Apns\Channels\StoragePolicy;
use Padosoft\MobilePush\Core\Exceptions\ConfigurationException;
use Padosoft\MobilePush\Core\Exceptions\InvalidDestinationException;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Policy\EndpointCatalog;
use Padosoft\MobilePush\Core\Policy\ExecutionMode;
use Padosoft\MobilePush\Core\Policy\OperationKind;
use Padosoft\MobilePush\Core\Policy\SafeModeDecision;
use Padosoft\MobilePush\Core\Transport\ConnectionProfile;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Tests\Scenario\Apns\Channels\ScriptedTokenProvider;

function channelBinding(Environment $env = Environment::Sandbox): BindingRef
{
    return new BindingRef('default', 'main', Provider::Apns, 'com.example.MyApp', $env);
}

function channelClient(FakeTransport $transport, ?ScriptedTokenProvider $tokens = null, Environment $env = Environment::Sandbox, ?Closure $gate = null): ChannelClient
{
    return new ChannelClient(
        $transport,
        new ConnectionProfile('apns-channels-test'),
        new EndpointCatalog('production'),
        channelBinding($env),
        $tokens,
        $gate,
    );
}

function reasonBody(string $reason): string
{
    return json_encode(['reason' => $reason], JSON_THROW_ON_ERROR);
}

it('walks a channel through create, read, delete and list with Apple documented shapes', function () {
    $transport = (new FakeTransport)->push(
        new Response(201, ['apns-channel-id' => 'dHN0LXNyY2gtY2hubA==', 'apns-request-id' => 'echo']),
        new Response(200, [], '{"message-storage-policy":1,"push-type":"LiveActivity"}'),
        new Response(204),
        new Response(200, [], '{"channels":["dHN0LXNyY2gtY2hubA==","b3RoZXI="]}'),
    );
    $client = channelClient($transport, new ScriptedTokenProvider);

    $channel = $client->create(StoragePolicy::MostRecent);
    $read = $client->get($channel->id);
    $client->delete($channel->id);
    $ids = $client->all();

    expect($channel)->toEqual(new Channel('dHN0LXNyY2gtY2hubA==', StoragePolicy::MostRecent))
        ->and($read->storagePolicy)->toBe(StoragePolicy::MostRecent)
        ->and($read->pushType)->toBe('LiveActivity')
        ->and($ids)->toBe(['dHN0LXNyY2gtY2hubA==', 'b3RoZXI=']);

    [$create, $get, $delete, $list] = $transport->requests();
    $base = 'https://api-manage-broadcast.sandbox.push.apple.com:2195/1/apps/com.example.MyApp';

    expect($create->method)->toBe('POST')
        ->and($create->url)->toBe($base.'/channels')
        ->and(json_decode($create->body, true))->toBe(['message-storage-policy' => 1, 'push-type' => 'LiveActivity'])
        ->and($create->headers['authorization'])->toBe('bearer jwt-1')
        ->and($create->headers['apns-request-id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($get->method)->toBe('GET')
        ->and($get->url)->toBe($base.'/channels')
        ->and($get->headers['apns-channel-id'])->toBe('dHN0LXNyY2gtY2hubA==')
        ->and($get->body)->toBe('')
        ->and($delete->method)->toBe('DELETE')
        ->and($delete->url)->toBe($base.'/channels')
        ->and($delete->headers['apns-channel-id'])->toBe('dHN0LXNyY2gtY2hubA==')
        ->and($list->method)->toBe('GET')
        ->and($list->url)->toBe($base.'/all-channels')
        ->and($list->headers)->not->toHaveKey('apns-channel-id');
});

it('uses mutual TLS without an authorization header when no token provider is given', function () {
    $transport = (new FakeTransport)->push(new Response(200, [], '{"channels":[]}'));

    channelClient($transport)->all();

    expect($transport->requests()[0]->headers)->not->toHaveKey('authorization');
});

it('never talks to the production management host from a sandbox binding, and the other way round', function () {
    $transport = (new FakeTransport)->defaultTo(new Response(200, [], '{"channels":[]}'));

    channelClient($transport, null, Environment::Sandbox)->all();
    channelClient($transport, null, Environment::Production)->all();

    $urls = array_map(static fn ($r) => $r->url, $transport->requests());

    expect($urls)->toBe([
        'https://api-manage-broadcast.sandbox.push.apple.com:2195/1/apps/com.example.MyApp/all-channels',
        'https://api-manage-broadcast.push.apple.com:2196/1/apps/com.example.MyApp/all-channels',
    ]);
});

it('does not retry a create whose response was lost, and reconcile finds the orphan', function () {
    $transport = (new FakeTransport)->push(
        new TransportFailure(TransportEvidence::Unknown, 28, 'timeout after the request was written'),
        new Response(200, [], '{"channels":["a25vd24=","b3JwaGFu"]}'),
    );
    $client = channelClient($transport, new ScriptedTokenProvider);

    try {
        $client->create(StoragePolicy::NoStorage);
        $thrown = null;
    } catch (ChannelOutcomeUnknownException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(ChannelOutcomeUnknownException::class)
        ->and($thrown->getMessage())->toContain('unknown')->toContain('reconcile')
        ->and($thrown->reason->code)->toBe(ReasonCode::TransportUnknown)
        ->and($transport->requests())->toHaveCount(1);

    $reconciliation = $client->reconcile(['a25vd24=', 'Z29uZQ==']);

    expect($reconciliation->unknownLocally)->toBe(['b3JwaGFu'])
        ->and($reconciliation->missingRemotely)->toBe(['Z29uZQ=='])
        ->and($reconciliation->isConsistent())->toBeFalse()
        ->and($transport->requests())->toHaveCount(2)
        ->and($transport->requests()[1]->url)->toEndWith('/all-channels');
});

it('treats a create that is proven not sent as a plain, safely retryable failure', function () {
    $transport = (new FakeTransport)->push(new TransportFailure(TransportEvidence::NotSent, 6, 'could not resolve host', false));

    try {
        channelClient($transport)->create(StoragePolicy::NoStorage);
    } catch (ChannelApiException $e) {
        expect($e)->not->toBeInstanceOf(ChannelOutcomeUnknownException::class)
            ->and($e->reason->code)->toBe(ReasonCode::TransportNotSent);

        return;
    }

    throw new LogicException('expected an exception');
});

it('flags a 201 without a channel id as an unknown outcome', function () {
    $transport = (new FakeTransport)->push(new Response(201));

    expect(fn () => channelClient($transport)->create(StoragePolicy::NoStorage))->toThrow(ChannelOutcomeUnknownException::class);
});

it('refreshes the provider token exactly once and retries exactly once when it expired mid-operation', function () {
    $tokens = new ScriptedTokenProvider;
    $transport = (new FakeTransport)->push(
        new Response(403, [], reasonBody('ExpiredProviderToken')),
        new Response(201, ['apns-channel-id' => 'bmV3']),
    );

    $channel = channelClient($transport, $tokens)->create(StoragePolicy::NoStorage);

    expect($channel->id)->toBe('bmV3')
        ->and($tokens->refreshes)->toBe(1)
        ->and($transport->requests())->toHaveCount(2)
        ->and($transport->requests()[0]->headers['authorization'])->toBe('bearer jwt-1')
        ->and($transport->requests()[1]->headers['authorization'])->toBe('bearer jwt-2');
});

it('does not loop when the refreshed token is rejected too', function () {
    $tokens = new ScriptedTokenProvider;
    $transport = (new FakeTransport)->defaultTo(new Response(403, [], reasonBody('ExpiredProviderToken')));

    try {
        channelClient($transport, $tokens)->all();
    } catch (ChannelApiException $e) {
        expect($e->reason->code)->toBe(ReasonCode::AuthTokenExpired)
            ->and($e->status)->toBe(403)
            ->and($tokens->refreshes)->toBe(1)
            ->and($transport->requests())->toHaveCount(2)
            ->and($e->getMessage())->not->toContain('jwt-');

        return;
    }

    throw new LogicException('expected an exception');
});

it('types the documented failures', function (int $status, string $reason, ReasonCode $code) {
    $transport = (new FakeTransport)->push(new Response($status, ['apns-request-id' => 'req-123'], reasonBody($reason)));

    try {
        channelClient($transport, new ScriptedTokenProvider)->create(StoragePolicy::NoStorage);
    } catch (ChannelApiException $e) {
        expect($e->reason->code)->toBe($code)
            ->and($e->reason->rawReason)->toBe($reason)
            ->and($e->status)->toBe($status)
            ->and($e->requestId)->toBe('req-123')
            ->and($e->getMessage())->not->toContain('jwt-1');

        return;
    }

    throw new LogicException('expected an exception');
})->with([
    'feature not enabled' => [403, 'FeatureNotEnabled', ReasonCode::FeatureNotEnabled],
    'limit reached' => [400, 'CannotCreateChannelConfig', ReasonCode::ChannelLimitReached],
    'bad channel id' => [400, 'BadChannelId', ReasonCode::ChannelUnknown],
    'invalid token' => [403, 'InvalidProviderToken', ReasonCode::AuthTokenInvalid],
    'too many requests' => [429, 'TooManyRequests', ReasonCode::QuotaExceeded],
    'server error' => [500, 'InternalServerError', ReasonCode::ProviderInternal],
    'unavailable' => [503, 'ServiceUnavailable', ReasonCode::ProviderUnavailable],
]);

it('touches nothing in simulate mode', function () {
    $transport = new FakeTransport;
    $client = channelClient($transport, new ScriptedTokenProvider, gate: static fn (OperationKind $op): SafeModeDecision => SafeModeDecision::simulate('Simulation mode: no request was sent.'));

    foreach ([
        fn () => $client->create(StoragePolicy::NoStorage),
        fn () => $client->get('dHN0'),
        fn () => $client->delete('dHN0'),
        fn () => $client->all(),
        fn () => $client->reconcile([]),
    ] as $operation) {
        try {
            $operation();
            $caught = null;
        } catch (ChannelApiException $e) {
            $caught = $e;
        }

        expect($caught?->reason->code)->toBe(ReasonCode::Simulated)
            ->and($caught->isRefusedBeforeNetwork())->toBeTrue();
    }

    expect($transport->requests())->toBe([]);
});

it('asks the guard for the channel-management operation and honours a block', function () {
    $asked = [];
    $transport = new FakeTransport;
    $client = channelClient($transport, null, gate: function (OperationKind $op) use (&$asked): SafeModeDecision {
        $asked[] = $op;

        return SafeModeDecision::block(ExecutionMode::Live, 'Blocked: needs live.allow_channel_management.');
    });

    expect(fn () => $client->all())->toThrow(ChannelApiException::class, 'allow_channel_management');
    expect($asked)->toBe([OperationKind::ChannelManagement])
        ->and($transport->requests())->toBe([]);
});

it('refuses a malformed channel id before touching the network', function () {
    $transport = new FakeTransport;

    try {
        channelClient($transport)->delete("abc\r\nx-evil: 1");
    } catch (InvalidDestinationException) {
        expect($transport->requests())->toBe([]);

        return;
    }

    throw new LogicException('expected an exception');
});

it('rejects a non-APNs binding', function () {
    new ChannelClient(new FakeTransport, new ConnectionProfile('x'), new EndpointCatalog('production'), new BindingRef('s', 'n', Provider::Fcm, 'proj', Environment::Production));
})->throws(ConfigurationException::class);
