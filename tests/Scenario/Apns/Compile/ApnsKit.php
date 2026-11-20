<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Scenario\Apns\Compile;

use Padosoft\MobilePush\Core\Apns\ApnsCompiler;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsBroadcastChannel;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsDeviceToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsLiveActivityToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsPushToStartToken;
use Padosoft\MobilePush\Core\Identity\Destination\ApnsVoipToken;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\CompileContext;
use Padosoft\MobilePush\Core\Message\CompiledMessage;

/** Shared fixtures for the APNs compilation scenarios. */
final class ApnsKit
{
    public const BUNDLE = 'com.example.app';

    public static function compiler(): ApnsCompiler
    {
        return new ApnsCompiler;
    }

    public static function binding(Provider $provider = Provider::Apns): BindingRef
    {
        return new BindingRef('default', 'main', $provider, self::BUNDLE, Environment::Production);
    }

    public static function context(
        string $now = '2026-11-20T10:00:00Z',
        ?string $expiresAt = null,
        ?string $messageId = null,
        ?string $receiptMarker = null,
    ): CompileContext {
        return new CompileContext(
            binding: self::binding(),
            now: new \DateTimeImmutable($now),
            expiresAt: $expiresAt === null ? null : new \DateTimeImmutable($expiresAt),
            messageId: $messageId,
            receiptMarker: $receiptMarker,
        );
    }

    public static function device(): ApnsDeviceToken
    {
        return ApnsDeviceToken::of(str_repeat('ab', 32));
    }

    public static function voipToken(): ApnsVoipToken
    {
        return ApnsVoipToken::of(str_repeat('cd', 32));
    }

    public static function startToken(): ApnsPushToStartToken
    {
        return ApnsPushToStartToken::of(str_repeat('ef', 32));
    }

    public static function activityToken(): ApnsLiveActivityToken
    {
        return ApnsLiveActivityToken::of(str_repeat('12', 32));
    }

    public static function channel(): ApnsBroadcastChannel
    {
        return ApnsBroadcastChannel::of('dHN0LXNyY2gtY2hubA==');
    }

    /** @return array{source: string, retrieved: string, payload: array<string, mixed>, headers?: array<string, string>} */
    public static function fixture(string $name): array
    {
        $json = file_get_contents(__DIR__.'/../../../Fixtures/apns/'.$name.'.json');
        assert(is_string($json));

        /** @var array{source: string, retrieved: string, payload: array<string, mixed>, headers?: array<string, string>} */
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public static function decode(CompiledMessage $compiled): array
    {
        /** @var array<string, mixed> */
        return json_decode($compiled->body, true, 512, JSON_THROW_ON_ERROR);
    }
}
