<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

use Padosoft\MobilePush\Core\Apns\ApnsCompiler;
use Padosoft\MobilePush\Core\Fcm\FcmCompiler;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\CompileContext;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Policy\CapabilityMatrix;
use Padosoft\MobilePush\Core\Policy\Support;
use Padosoft\MobilePush\Laravel\Sending\PushManager;
use Psr\Clock\ClockInterface;

/**
 * Compiles a message locally for a PLACEHOLDER destination: no network, no credential check beyond what resolving a
 * named connection already does. A real token is never needed and never printed.
 */
final class LocalCompiler
{
    public const IOS_PLACEHOLDER_APP = 'com.example.app';

    public const ANDROID_PLACEHOLDER_APP = 'example-firebase-project';

    public function __construct(private readonly PushManager $manager, private readonly ClockInterface $clock) {}

    /**
     * @throws ConsoleInputException when neither a platform nor a connection says what to compile for
     */
    public function compile(PushMessage $message, ?Platform $platform, ?string $connection, ?string $scope, ?string $bundle = null): LocalPreview
    {
        $scope ??= $this->manager->currentScope();

        if ($connection !== null) {
            $binding = $this->manager->binding($connection, $scope);
            $compiler = $this->manager->driver($binding)->compiler();
            $platform = $binding->provider === Provider::Apns ? Platform::Ios : Platform::Android;
            $label = sprintf('connection "%s" (%s, app %s, %s)', $connection, $binding->provider->value, $binding->appId, $binding->environment->value);
            $placeholder = false;
        } else {
            if ($platform === null) {
                throw new ConsoleInputException('Say what to compile for: --platform=ios|android or --connection=NAME. The platform is never guessed.');
            }
            $provider = $platform === Platform::Ios ? Provider::Apns : Provider::Fcm;
            $app = $bundle ?? ($platform === Platform::Ios ? self::IOS_PLACEHOLDER_APP : self::ANDROID_PLACEHOLDER_APP);
            $binding = new BindingRef($scope, 'preview', $provider, $app, Environment::Production, 'preview');
            $compiler = $provider === Provider::Apns ? new ApnsCompiler : new FcmCompiler;
            $label = sprintf('placeholder %s binding (app %s, production)', $provider->value, $app);
            $placeholder = true;
        }

        $ios = $platform === Platform::Ios;
        $destination = $ios ? ApnsDeviceToken::of(str_repeat('0', 64)) : FcmToken::of('PLACEHOLDER-FCM-REGISTRATION-TOKEN');
        $compiled = $compiler->compile($message, $destination, new CompileContext($binding, $this->clock->now()));

        [$appCode, $unsupported] = $this->notes($message, $platform);

        return new LocalPreview(
            $ios ? 'apns' : 'fcm', $platform->value, $label, $placeholder,
            $ios ? '<APNs device token placeholder>' : '<FCM registration token placeholder>',
            $compiled, $appCode, $unsupported,
        );
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function notes(PushMessage $m, Platform $platform): array
    {
        $used = array_keys(array_filter([
            'title' => $m->title !== null,
            'subtitle' => $m->subtitle !== null,
            'body' => $m->body !== null,
            'image' => $m->image !== null,
            'deep_link' => $m->deepLink !== null,
            'actions' => $m->actionCategory !== null,
            'badge' => $m->badge !== null || $m->incrementBadge,
            'sound' => $m->sound !== null,
            'critical' => $m->critical,
            'time_sensitive' => $m->timeSensitive,
            'silent' => $m->silent,
            'group' => $m->group !== null,
            'collapse' => $m->collapseKey !== null,
            'mutable_content' => $m->mutableContent,
            'expiry' => $m->expiresAt !== null || $m->ttlSeconds !== null,
            'data' => $m->data !== [],
        ]));

        $matrix = new CapabilityMatrix;
        $appCode = [];
        $unsupported = [];
        foreach ($used as $capability) {
            $cell = $matrix->support($capability, $platform);
            $text = '`'.$capability.'`'.($cell->note !== null ? ': '.$cell->note : '');
            if ($cell->support === Support::RequiresAppCode) {
                $appCode[] = $text;
            } elseif ($cell->support === Support::Unavailable) {
                $unsupported[] = $text;
            }
        }

        return [$appCode, $unsupported];
    }
}
