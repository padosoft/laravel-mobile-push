<?php

declare(strict_types=1);

use Illuminate\Notifications\Notifiable;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Laravel\Facades\MobilePush;

/**
 * Executes the PHP examples of README.md and docs/*.md, so a documented snippet cannot rot.
 *
 * An example is a fenced ```php block immediately preceded by an HTML comment:
 *
 *     <!-- example: short-id -->
 *     ```php
 *     MobilePush::toToken(Platform::Ios, $iosToken)->send(PushMessage::make('Hi', 'There'));
 *     ```
 *
 * Each example runs on its own, inside an application where `MobilePush::fake()` is active (nothing is
 * compiled or sent, no credentials needed), with these variables in scope:
 *   $fake          the PushFake (so an example may end with an assertion)
 *   $iosToken      a syntactically valid APNs device token (string)
 *   $androidToken  a syntactically valid FCM registration token (string)
 *   $user          a notifiable with one iOS and one Android destination
 * An example may start with its own `use` statements. Blocks without the marker are illustrative only.
 */
final class DocExampleUser
{
    use Notifiable;

    public int $id = 42;

    /** @return list<ApnsDeviceToken|FcmToken> */
    public function routeNotificationForPush(mixed $notification = null): array
    {
        return [
            ApnsDeviceToken::of(str_repeat('ab', 32)),
            FcmToken::of('dOcExAmPlE-fcm-registration-token:APA91bExample'),
        ];
    }

    public function getKey(): int
    {
        return $this->id;
    }
}

/**
 * @return array<string, array{string}>
 */
function documentedExamples(): array
{
    $root = dirname(__DIR__, 4);
    $files = array_merge(glob($root.'/docs/*.md') ?: [], [$root.'/README.md']);
    $examples = [];

    foreach ($files as $file) {
        if (! is_file($file)) {
            continue;
        }

        $markdown = (string) file_get_contents($file);
        preg_match_all('/<!--\s*example:\s*([a-z0-9-]+)\s*-->\s*```php\R(.*?)```/s', $markdown, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $examples[basename($file).'#'.$match[1]] = [$match[2]];
        }
    }

    return $examples === [] ? ['(no documented examples yet)' => ['']] : $examples;
}

it('runs the documented example', function (string $code) {
    $fake = MobilePush::fake();
    $iosToken = str_repeat('ab', 32);
    $androidToken = 'dOcExAmPlE-fcm-registration-token:APA91bExample';
    $user = new DocExampleUser;

    $code = (string) preg_replace('/^<\?php\s*/', '', ltrim($code));

    (static function () use ($code, $fake, $iosToken, $androidToken, $user): void {
        eval($code);
    })();

    expect($fake)->not->toBeNull();
})->with(fn () => documentedExamples());
