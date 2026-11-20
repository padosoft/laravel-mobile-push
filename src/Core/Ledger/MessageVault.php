<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Privacy\PrivacyException;
use Padosoft\MobilePush\Core\Privacy\TokenCipher;

/**
 * Encrypted snapshot of a message, so the relay can send it later without the caller.
 *
 * The serialized message is sealed with TokenCipher (AES-256-GCM) and bound to the scope and to the effect id: a
 * snapshot copied to another effect or scope fails authentication. Because the plaintext is authenticated before
 * unserialize() runs, a database writer without the key cannot inject objects.
 */
final readonly class MessageVault
{
    public function __construct(private TokenCipher $cipher) {}

    public function seal(Message $message, string $scope, string $effectId): string
    {
        return $this->cipher->encrypt(serialize($message), $scope, self::context($effectId));
    }

    /** @throws PrivacyException when the snapshot does not authenticate or is not a message */
    public function open(string $sealed, string $scope, string $effectId): Message
    {
        $message = unserialize($this->cipher->decrypt($sealed, $scope, self::context($effectId)));

        if (! $message instanceof Message) {
            throw new PrivacyException('The stored message snapshot is not a message.');
        }

        return $message;
    }

    private static function context(string $effectId): string
    {
        return 'ledger-message|'.$effectId;
    }
}
