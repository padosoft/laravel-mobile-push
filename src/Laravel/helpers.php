<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Outcome\SendReport;
use Padosoft\MobilePush\Laravel\Sending\PendingSend;
use Padosoft\MobilePush\Laravel\Sending\PushManager;

if (! function_exists('mobile_push')) {
    /**
     * The push manager, or a pending send to `$target` (typed destination, owner model, notifiable or iterable).
     *
     *     mobile_push($user)->send(PushMessage::make('Order shipped', 'Arrives Friday'));
     */
    function mobile_push(mixed $target = null): PendingSend|PushManager
    {
        $manager = app(PushManager::class);

        return $target === null ? $manager : $manager->to($target);
    }
}

if (! function_exists('mobile_push_to_token')) {
    /**
     * Send one push to one raw device token, now. The platform is mandatory: it is never guessed from the token.
     * Without `$connection` the only connection of that platform's provider is used.
     *
     *     mobile_push_to_token(Platform::Ios, $token, 'Title', 'Body', ['order' => '42']);
     *
     * @param  array<string, mixed>  $data  custom data (ignored when a Message is given)
     */
    function mobile_push_to_token(
        Platform $platform,
        #[\SensitiveParameter] string $token,
        string|Message $titleOrMessage,
        ?string $body = null,
        array $data = [],
        ?string $connection = null,
    ): SendReport {
        $message = $titleOrMessage instanceof Message
            ? $titleOrMessage
            : PushMessage::make($titleOrMessage, $body)->data($data);

        $manager = app(PushManager::class);
        $pending = $connection === null ? $manager->to(null) : $manager->connection($connection);

        return $pending->toToken($platform, $token)->send($message);
    }
}
