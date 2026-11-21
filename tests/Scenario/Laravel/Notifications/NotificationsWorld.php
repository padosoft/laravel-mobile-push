<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Laravel\Notifications;

use Illuminate\Support\Facades\Event;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;
use Padosoft\MobilePush\Laravel\Notifications\PushFellBack;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Notifications\Fixtures\Shopper;
use Padosoft\MobilePush\Tests\Scenario\Laravel\Sending\SendingWorld;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * The shop: an iOS app and an Android app, the device registry on SQLite in memory, email going to the "array"
 * mailer (the oracle for the fallback), FakeTransport as the provider oracle.
 */
final class NotificationsWorld
{
    public SendingWorld $push;

    /** @var list<PushFellBack> */
    public array $fellBack = [];

    /** @var list<string> */
    public array $tokens = [];

    /** @param  array<string, mixed>  $config */
    public static function install(array $config = []): self
    {
        $world = new self;
        $world->push = SendingWorld::install(array_replace([
            'connections' => ['ios' => SendingWorld::apns(), 'android' => SendingWorld::fcm()],
        ], $config))->withDevices();
        config()->set('mail.default', 'array');
        config()->set('mail.from', ['address' => 'shop@example.test', 'name' => 'Shop']);
        Event::listen(PushFellBack::class, function (PushFellBack $event) use ($world): void {
            $world->fellBack[] = $event;
        });

        return $world;
    }

    public function iphone(Shopper $user, string $seed = 'iphone', string $connection = 'ios', ?string $scope = null): string
    {
        $token = SendingWorld::apnsToken($seed);
        $this->push->register(MobilePush::binding($connection, $scope), ApnsDeviceToken::of($token), $user);
        $this->tokens[] = $token;

        return $token;
    }

    public function android(Shopper $user, string $seed = 'android', string $connection = 'android'): string
    {
        $token = 'fake-fcm-registration-token-'.$seed;
        $this->push->register(MobilePush::binding($connection), FcmToken::of($token), $user);
        $this->tokens[] = $token;

        return $token;
    }

    /** Every APNs request answers 410 Unregistered (the app was uninstalled). */
    public function appUninstalled(): void
    {
        $this->push->answer = static fn (Request $r): ?Response => str_contains($r->url, 'api.push.apple.com')
            ? new Response(410, ['apns-id' => '11111111-2222-4333-8444-555555555555'], (string) json_encode(['reason' => 'Unregistered']))
            : null;
    }

    public function mailCount(): int
    {
        return count($this->mails());
    }

    /** @return list<SentMessage> */
    public function mails(): array
    {
        $transport = app('mail.manager')->mailer('array')->getSymfonyTransport();
        assert($transport instanceof TransportInterface && method_exists($transport, 'messages'));

        return $transport->messages()->all();
    }
}
