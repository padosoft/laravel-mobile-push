<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Pulse;

use Padosoft\MobilePush\Core\Driver\GuardKind;
use Padosoft\MobilePush\Core\Guard\BreakerTransition;
use Padosoft\MobilePush\Core\Guard\TrafficClass;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Outcome\Reason;
use Padosoft\MobilePush\Core\Outcome\ReasonCode;
use Padosoft\MobilePush\Core\Outcome\SendResult;
use Padosoft\MobilePush\Core\Outcome\SendStatus;
use Padosoft\MobilePush\Core\Privacy\KeyRing;
use Padosoft\MobilePush\Core\Privacy\TokenIndex;
use Padosoft\MobilePush\Laravel\Sending\Events\CircuitClosed;
use Padosoft\MobilePush\Laravel\Sending\Events\CircuitOpened;
use Padosoft\MobilePush\Laravel\Sending\Events\ProviderThrottled;
use Padosoft\MobilePush\Laravel\Sending\Events\PushBatchCompleted;
use Padosoft\MobilePush\Laravel\Sending\Events\PushResultProduced;
use Padosoft\MobilePush\Laravel\Sending\Events\ResultView;

/** Builds realistic package events. */
final class Kit
{
    public static function binding(string $name = 'main', Provider $provider = Provider::Apns, Environment $env = Environment::Production): BindingRef
    {
        return new BindingRef('default', $name, $provider, 'com.example.app', $env);
    }

    public static function result(
        BindingRef $binding,
        SendStatus $status,
        ReasonCode $code = ReasonCode::None,
        ?string $token = null,
        ?string $raw = null,
        ?string $hint = null,
        TrafficClass $class = TrafficClass::Transactional,
        ?string $messageId = null,
    ): PushResultProduced {
        $token ??= 'tok-'.bin2hex(random_bytes(8)).str_repeat('Zq9', 20);
        $send = new SendResult(
            FcmToken::of($token),
            $status,
            new Reason($code, $raw, $hint),
            attempts: 1,
            providerMessageId: $messageId,
            httpStatus: $status === SendStatus::Accepted ? 200 : 400,
            deliveryId: '01HZZZZZZZZZZZZZZZZZZZZZZZ',
        );
        $view = ResultView::of($send, new TokenIndex(new KeyRing([1 => str_repeat('k', 32)])), 'default');

        return new PushResultProduced($binding, $view, $class, $send);
    }

    /** @param  array<string, int>  $counts  status value => count */
    public static function batch(BindingRef $binding, array $counts, float $ms = 120.0, TrafficClass $class = TrafficClass::Bulk): PushBatchCompleted
    {
        $all = [];
        foreach (SendStatus::cases() as $status) {
            $all[$status->value] = $counts[$status->value] ?? 0;
        }

        return new PushBatchCompleted($binding, $all, array_sum($all), $class, $ms);
    }

    public static function throttled(BindingRef $binding, int $seconds = 300): ProviderThrottled
    {
        return new ProviderThrottled($binding, 'account-key', new \DateTimeImmutable('+'.$seconds.' seconds'));
    }

    public static function circuitOpened(BindingRef $binding): CircuitOpened
    {
        return new CircuitOpened($binding, GuardKind::Auth, BreakerTransition::Opened, 1);
    }

    public static function circuitClosed(BindingRef $binding): CircuitClosed
    {
        return new CircuitClosed($binding, GuardKind::Auth, BreakerTransition::Closed, 1);
    }
}
