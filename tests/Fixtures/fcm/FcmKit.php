<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests\Fixtures\fcm;

use Padosoft\MobilePush\Core\Fcm\FcmCompiler;
use Padosoft\MobilePush\Core\Identity\BindingRef;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\Destination\FcmCondition;
use Padosoft\MobilePush\Core\Identity\Destination\FcmFid;
use Padosoft\MobilePush\Core\Identity\Destination\FcmToken;
use Padosoft\MobilePush\Core\Identity\Destination\FcmTopic;
use Padosoft\MobilePush\Core\Identity\Environment;
use Padosoft\MobilePush\Core\Identity\Provider;
use Padosoft\MobilePush\Core\Message\CompileContext;
use Padosoft\MobilePush\Core\Message\CompiledMessage;
use Padosoft\MobilePush\Core\Message\Message;

/** Small helpers shared by the FCM tests. Test data only: no real tokens. */
final class FcmKit
{
    public const NOW = '2026-11-20T10:00:00Z';

    public static function binding(): BindingRef
    {
        return new BindingRef('default', 'android', Provider::Fcm, 'demo-project', Environment::Production);
    }

    public static function context(
        ?string $now = null,
        ?string $expiresAt = null,
        bool $validateOnly = false,
        ?string $receiptMarker = null,
    ): CompileContext {
        return new CompileContext(
            binding: self::binding(),
            now: new \DateTimeImmutable($now ?? self::NOW),
            expiresAt: $expiresAt === null ? null : new \DateTimeImmutable($expiresAt),
            validateOnly: $validateOnly,
            receiptMarker: $receiptMarker,
        );
    }

    public static function token(): FcmToken
    {
        return FcmToken::of('tok_'.str_repeat('A1b2C3d4', 12));
    }

    public static function fid(): FcmFid
    {
        return FcmFid::of('cAbCdEfGhIjKlMnOpQrStU');
    }

    public static function topic(string $name = 'weather'): FcmTopic
    {
        return FcmTopic::of($name);
    }

    public static function condition(string $expr = "'sports' in topics && 'news' in topics"): FcmCondition
    {
        return FcmCondition::of($expr);
    }

    public static function compile(Message $message, ?Destination $destination = null, ?CompileContext $context = null): CompiledMessage
    {
        return (new FcmCompiler)->compile($message, $destination ?? self::token(), $context ?? self::context());
    }

    /** @return array<string, mixed> */
    public static function decode(CompiledMessage $compiled): array
    {
        /** @var array<string, mixed> */
        return json_decode($compiled->body, true, 512, JSON_THROW_ON_ERROR);
    }
}
