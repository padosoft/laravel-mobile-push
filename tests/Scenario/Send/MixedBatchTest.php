<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\DestinationAction;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Transport\FakeTransport;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Core\Transport\TransportEvidence;
use Padosoft\MobilePush\Core\Transport\TransportFailure;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

/*
 * Story: an order-shipped alert goes to three iPhones. APNs accepts the first, says the second app was uninstalled
 * (410 with a millisecond timestamp), and the connection drops before the third answer arrives.
 */
it('reports exactly one current outcome per destination of a mixed batch, without throwing', function () {
    $kit = new SendKit;
    $tokens = [SendKit::apnsToken(1), SendKit::apnsToken(2), SendKit::apnsToken(3)];
    $transport = (new FakeTransport)->respondUsing(function (Request $request) use ($tokens) {
        return match (SendKit::tokenOf($request)) {
            $tokens[0]->value() => new Response(200, ['apns-id' => $request->headers['apns-id'], 'apns-unique-id' => 'u-1']),
            $tokens[1]->value() => SendKit::apnsReason(410, 'Unregistered', ['timestamp' => 1_763_600_000_123]),
            default => new TransportFailure(TransportEvidence::Unknown, 92, 'HTTP/2 stream was reset after the request was written'),
        };
    });

    $report = $kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('Shipped', 'Your order is on its way'), $tokens, SendKit::live());
    $results = SendKit::all($report);

    expect($transport->requests())->toHaveCount(3)
        ->and($results)->toHaveCount(3)
        ->and(array_map(fn ($r) => $r->destination->value(), $results))->toBe(array_map(fn ($t) => $t->value(), $tokens));

    [$accepted, $gone, $unknown] = $results;
    expect($accepted->status)->toBe(SendStatus::Accepted)
        ->and($accepted->attempts)->toBe(1)
        ->and($accepted->httpStatus)->toBe(200)
        ->and($accepted->providerMessageId)->toBe($transport->requests()[0]->headers['apns-id'])
        ->and($accepted->meta['apns-unique-id'])->toBe('u-1');

    expect($gone->status)->toBe(SendStatus::Rejected)
        ->and($gone->reason->code)->toBe(ReasonCode::DestinationUnregistered)
        ->and($gone->reason->rawReason)->toBe('Unregistered')
        ->and($gone->destinationAction)->toBe(DestinationAction::Invalidate)
        ->and($gone->invalidatedAtMs)->toBe(1_763_600_000_123);

    expect($unknown->status)->toBe(SendStatus::OutcomeUnknown)
        ->and($unknown->reason->code)->toBe(ReasonCode::TransportUnknown)
        ->and($unknown->destinationAction)->toBe(DestinationAction::None)
        ->and($unknown->status->allowsFallback())->toBeFalse();

    // The counters of a report agree with the results it streamed.
    expect($report->counts())->toMatchArray(['accepted' => 1, 'rejected' => 1, 'outcome_unknown' => 1])
        ->and($report->count())->toBe(3)
        ->and($report->hasUnknown())->toBeTrue();
});

it('sends what APNs expects on the wire: official URL, bearer JWT, topic, push type, canonical apns-id', function () {
    $kit = new SendKit;
    $transport = new FakeTransport;
    $token = SendKit::apnsToken(7);

    $kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('Hi', 'There'), [$token], SendKit::live())->counts();

    [$entry] = $transport->recorded();
    $request = $entry['request'];
    expect($request->method)->toBe('POST')
        ->and($request->url)->toBe('https://api.push.apple.com/3/device/'.$token->value())
        ->and($request->headers['authorization'])->toStartWith('bearer eyJ')
        ->and($request->headers['apns-topic'])->toBe(SendKit::BUNDLE)
        ->and($request->headers['apns-push-type'])->toBe('alert')
        ->and($request->headers['apns-id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and(json_decode($request->body, true))->toBe(['aps' => ['alert' => ['title' => 'Hi', 'body' => 'There']]])
        ->and($entry['profile']->requireHttp2)->toBeTrue()
        ->and($entry['profile']->poolKey)->toStartWith('apns:apns_send:');
});

it('keeps every destination in the report when the transport loses an exchange or throws mid-batch', function () {
    $kit = new SendKit;
    $transport = new class implements \Padosoft\MobilePush\Core\Transport\Transport
    {
        public int $requests = 0;

        public function send(iterable $requests, \Padosoft\MobilePush\Core\Transport\ConnectionProfile $profile): \Generator
        {
            foreach ($requests as $request) {
                $this->requests++;
                if ($this->requests === 2) {
                    throw new \RuntimeException('transport bug after writing two requests');
                }
                yield $request->id => \Padosoft\MobilePush\Core\Transport\Exchange::responded($request, new Response(200));
            }
        }

        public function close(?string $poolKey = null): void {}
    };

    $results = SendKit::all($kit->sender($transport)->send($kit->apnsDriver(), PushMessage::make('A', 'B'), SendKit::apnsTokens(4), SendKit::live()));

    expect($results)->toHaveCount(4)
        ->and($results[0]->status)->toBe(SendStatus::Accepted)
        ->and(array_map(fn ($r) => $r->status, array_slice($results, 1)))->each->toBe(SendStatus::OutcomeUnknown);
});
