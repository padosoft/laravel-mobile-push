<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Laravel\Contracts\QueuedSender;
use Padosoft\MobilePush\Laravel\Contracts\QueueOptions;
use Padosoft\MobilePush\Laravel\Devices\DeviceRegistrar;
use Padosoft\MobilePush\Laravel\Queue\RelayDeliveriesJob;
use Padosoft\MobilePush\Laravel\Sending\Events\BindingMismatchDetected;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Devices\DeviceWorld;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Queue\QueueWorld;
use Padosoft\MobilePush\Tests\Scenario\Send\SendKit;

require_once __DIR__.'/../Devices/DeviceWorld.php';

/*
 * Durable profile, two APNs apps of one shop: "ios-a" (com.example.a) and "ios-b" (com.example.b). Alice's iPhone is
 * registered on ios-a. A push is queued through ios-b with her registry destination and relayed later. The relay must
 * send nothing (B's credentials never see A's device) and say the true cause, as a direct send does: the device is
 * fine, the connection is wrong (binding_mismatch, naming ios-a). Another tenant's connection names are never echoed;
 * a device row that is really gone keeps policy_ineligible / row_missing.
 */

beforeEach(function (): void {
    Queue::fake();
    $this->w = QueueWorld::boot('durable', devices: true);
    $this->a = SendKit::apnsBinding('ios-a', bundle: 'com.example.a');
    $this->b = SendKit::apnsBinding('ios-b', bundle: 'com.example.b');
    $this->w->dispatcher->add($this->w->kit->apnsDriver(binding: $this->a));
    $this->w->dispatcher->add($this->w->kit->apnsDriver(binding: $this->b));
    $this->queue = static fn (BindingRef $through, Destination ...$destinations): string => app(QueuedSender::class)
        ->queue($through, PushMessage::make('Order', 'Shipped')->ttl(7200), $destinations, SendKit::live(effectKey: 'order-42'), new QueueOptions);
    $this->relay = function (): void {
        Event::fake([BindingMismatchDetected::class]);
        $this->w->run(Queue::pushed(RelayDeliveriesJob::class)->first());
    };
});

it('a device registered on ios-a, queued through ios-b: nothing is sent, the delivery is skipped as binding_mismatch naming ios-a', function (): void {
    $phone = app(DeviceRegistrar::class)->register($this->a, QueueWorld::token(1), DeviceWorld::user(1));
    ($this->queue)($this->b, $phone->destination());

    ($this->relay)();

    $row = DB::table('mobile_push_deliveries')->first();
    expect($this->w->transport->sentCount())->toBe(0)
        ->and($row->state)->toBe('skipped')
        ->and($row->reason_code)->toBe('binding_mismatch')
        ->and((int) $row->attempts)->toBe(0)
        ->and($row->hint)->toContain('"ios-a"')
        ->and($row->hint)->toContain('"ios-b"')
        ->and($row->hint)->not->toContain(QueueWorld::token(1)->value());
    // the gate of a direct send reports this case without the connection-reassigned event: so does the relay
    Event::assertNotDispatched(BindingMismatchDetected::class);
});

it('a device of another tenant: binding_mismatch, without echoing the other tenant\'s scope or connection name', function (): void {
    $theirs = SendKit::apnsBinding('ios-tenant-x-private', scope: 'tenant-x', bundle: 'com.example.b');
    $phone = app(DeviceRegistrar::class)->register($theirs, QueueWorld::token(2), DeviceWorld::user(2));
    ($this->queue)($this->b, $phone->destination());

    ($this->relay)();

    $row = DB::table('mobile_push_deliveries')->first();
    expect($this->w->transport->sentCount())->toBe(0)
        ->and($row->state)->toBe('skipped')
        ->and($row->reason_code)->toBe('binding_mismatch')
        ->and($row->hint)->toContain('another scope')
        ->and($row->hint)->not->toContain('ios-tenant-x-private')
        ->and($row->hint)->not->toContain('tenant-x');
});

it('a device row pruned while the push waited keeps policy_ineligible / row_missing', function (): void {
    $phone = app(DeviceRegistrar::class)->register($this->a, QueueWorld::token(3), DeviceWorld::user(3));
    ($this->queue)($this->a, $phone->destination());
    DB::table('mobile_push_devices')->delete();

    ($this->relay)();

    $row = DB::table('mobile_push_deliveries')->first();
    expect($this->w->transport->sentCount())->toBe(0)
        ->and($row->state)->toBe('skipped')
        ->and($row->reason_code)->toBe('policy_ineligible')
        ->and($row->raw_reason)->toBe('row_missing');
});

it('the same device through its own connection is still resolved and sent once, with one registry read', function (): void {
    $phone = app(DeviceRegistrar::class)->register($this->a, QueueWorld::token(4), DeviceWorld::user(4));
    ($this->queue)($this->a, $phone->destination());

    DB::enableQueryLog();
    ($this->relay)();
    $registryReads = array_filter(DB::getQueryLog(), static fn (array $q): bool => str_starts_with(strtolower($q['query']), 'select')
        && str_contains($q['query'], 'mobile_push_devices'));

    // the extra lookup runs only for references missing from the binding
    expect($this->w->apnsRequestsPerToken())->toBe([QueueWorld::token(4)->value() => 1])
        ->and(QueueWorld::deliveryStates())->toBe(['accepted' => 1])
        ->and($registryReads)->toHaveCount(1);
});
