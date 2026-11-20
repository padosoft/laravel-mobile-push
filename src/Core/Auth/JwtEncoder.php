<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Auth;

use Padosoft\MobilePush\Core\Exceptions\AuthenticationException;

/** Builds a compact JWS (RFC 7515) signed by a {@see Signer}. */
final class JwtEncoder
{
    /**
     * `alg` always comes from the signer; `kid` is added from the signer when the header lacks one.
     *
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $claims
     */
    public function encode(array $header, array $claims, Signer $signer): string
    {
        $header['alg'] = $signer->algorithm();
        if (! isset($header['kid']) && $signer->keyId() !== null) {
            $header['kid'] = $signer->keyId();
        }

        $signingInput = self::base64UrlEncode(self::json($header)).'.'.self::base64UrlEncode(self::json($claims));

        return $signingInput.'.'.self::base64UrlEncode($signer->sign($signingInput));
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $text): string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);
        if ($decoded === false) {
            throw new AuthenticationException('Invalid base64url input.');
        }

        return $decoded;
    }

    /** @param  array<string, mixed>  $data */
    private static function json(array $data): string
    {
        try {
            return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AuthenticationException('The JWT header or claims are not JSON-encodable.');
        }
    }
}
