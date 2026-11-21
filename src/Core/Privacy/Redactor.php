<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Privacy;

use Padosoft\MobilePush\Core\Transport\Request;
use Padosoft\MobilePush\Core\Transport\Response;

/**
 * The single choke point used before ANY sink: logs, events, exceptions, telemetry, CLI output.
 *
 * Deterministic and idempotent: redacting an already redacted value changes nothing. Runtime secrets (bearer values,
 * receipt markers, raw tokens) are registered with withSecrets() and removed wherever they appear, including their
 * URL-encoded, JSON-escaped and base64 forms and each line of a multi-line secret.
 */
final readonly class Redactor
{
    public const MASK = '[redacted]';

    private const SECRET = '[redacted:secret]';

    private const PLACEHOLDERS = '[redacted] [redacted:secret] [redacted:jwt] [redacted:key] [redacted:token] Bearer';

    private const SENSITIVE_KEY_PARTS = [
        'token', 'secret', 'password', 'passwd', 'private_key', 'authorization', 'cookie', 'receipt',
        'channel_id', 'credential', 'api_key', 'apikey', 'bearer', 'x_goog',
    ];

    private const MESSAGE_KEYS = ['message', 'error', 'error_description', 'detail', 'details', 'status_message', 'reason', 'description'];

    /** @var list<string> */
    private array $needles;

    /** @var list<string> */
    private array $secrets;

    /**
     * @param  list<string>  $secrets
     */
    public function __construct(#[\SensitiveParameter] array $secrets = [])
    {
        $this->secrets = $secrets;
        $this->needles = self::expand($this->secrets);
    }

    /**
     * @param  list<string>  $secrets
     */
    public function withSecrets(#[\SensitiveParameter] array $secrets): self
    {
        return new self([...$this->secrets, ...$secrets]);
    }

    /** General text: known secrets, bearer values, JWTs, PEM keys, APNs device paths, credential-looking JSON fields. */
    public function string(string $text): string
    {
        foreach ($this->needles as $needle) {
            $text = str_replace($needle, self::SECRET, $text);
        }

        $text = (string) preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s', '[redacted:key]', $text);
        $text = (string) preg_replace('/\b(bearer)\s+[A-Za-z0-9._~+\/=-]{4,}/i', 'Bearer '.self::MASK, $text);
        $text = (string) preg_replace('/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/', '[redacted:jwt]', $text);
        $text = (string) preg_replace('#(/3/device/|/registrations/)[^\s/?\#"\'\\\\<>]+#', '$1'.self::MASK, $text);
        $text = (string) preg_replace('/("(?:[A-Za-z0-9_-]*(?:'.self::sensitiveKeyPattern().')[A-Za-z0-9_-]*)"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i', '$1"'.self::MASK.'"', $text);
        $text = (string) preg_replace('/([?&](?:[A-Za-z0-9_]*(?:token|secret|key|signature|password)[A-Za-z0-9_]*)=)[^&\s"\']+/i', '$1'.self::MASK, $text);
        $text = (string) preg_replace('#(https?://)[^/\s:@]+:[^/\s@]*@#', '$1'.self::MASK.'@', $text);

        return $text;
    }

    /**
     * Text produced by a provider (FCM `error.message`, APNs reasons, HTML error pages) that may echo a token:
     * everything string() does, plus any opaque run of 32+ token characters.
     */
    public function providerMessage(string $text): string
    {
        return (string) preg_replace('/[A-Za-z0-9_:\-.~+\/=]{32,}/', '[redacted:token]', $this->string($text));
    }

    public function url(string $url): string
    {
        return $this->string($url);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public function headers(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $value) {
            $out[$name] = self::isSensitiveKey((string) $name) ? self::MASK : $this->string($value);
        }

        return $out;
    }

    /**
     * Safe representation of a Request for diagnostics: the body is reduced to its size (it holds user content).
     *
     * @return array<string, mixed>
     */
    public function request(Request $request): array
    {
        return [
            'id' => $this->string($request->id),
            'method' => $request->method,
            'url' => $this->url($request->url),
            'headers' => $this->headers($request->headers),
            'body_bytes' => strlen($request->body),
            'timeout_ms' => $request->timeoutMs,
        ];
    }

    /**
     * Safe representation of a Response: provider bodies are redacted as provider messages and truncated.
     *
     * @return array<string, mixed>
     */
    public function response(Response $response): array
    {
        return [
            'status' => $response->status,
            'http_version' => $response->httpVersion,
            'headers' => $this->headers($response->headers),
            'body' => self::cut($this->providerMessage($response->body), 512),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function throwable(\Throwable $e): array
    {
        $out = [
            'class' => $e::class,
            'message' => $this->providerMessage($e->getMessage()),
            'code' => $e->getCode(),
            'location' => basename($e->getFile()).':'.$e->getLine(),
        ];

        if ($e->getPrevious() !== null) {
            $out['previous'] = $this->throwable($e->getPrevious());
        }

        return $out;
    }

    /** Redact any value: scalars, nested arrays, requests, responses, throwables. Objects otherwise become their class name. */
    public function value(mixed $value, ?string $key = null): mixed
    {
        return match (true) {
            $value === null || is_bool($value) || is_int($value) || is_float($value) => $value,
            $key !== null && self::isSensitiveKey($key) => self::MASK,
            is_string($value) => $key !== null && in_array(strtolower($key), self::MESSAGE_KEYS, true) ? $this->providerMessage($value) : $this->string($value),
            is_array($value) => $this->walk($value),
            $value instanceof Request => $this->request($value),
            $value instanceof Response => $this->response($value),
            $value instanceof \Throwable => $this->throwable($value),
            $value instanceof \Stringable => $this->providerMessage((string) $value),
            is_object($value) => '['.$value::class.']',
            default => self::MASK,
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function walk(array $data): array
    {
        $out = [];

        foreach ($data as $k => $v) {
            $safeKey = is_string($k) ? $this->string($k) : $k;
            $out[$safeKey] = $this->value($v, is_string($k) ? $k : null);
        }

        return $out;
    }

    /**
     * The same credential-looking key parts as isSensitiveKey(), as a regex alternation for keys found inside JSON
     * text ("_" also matches "-", as the array rule normalises it). One list for both: a key redacted in an array
     * must not survive when the same structure is logged as text.
     */
    private static function sensitiveKeyPattern(): string
    {
        return implode('|', array_map(static fn (string $part): string => str_replace('_', '[_-]', preg_quote($part, '/')), self::SENSITIVE_KEY_PARTS));
    }

    /** The first $bytes bytes of $text, never ending in the middle of a UTF-8 character (valid input stays valid). */
    private static function cut(string $text, int $bytes): string
    {
        $cut = substr($text, 0, $bytes);
        for ($i = 0; $i < 3 && $cut !== '' && preg_match('//u', $cut) !== 1 && preg_match('//u', $text) === 1; $i++) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace('-', '_', $key));

        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if (str_contains($normalized, $part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $secrets
     * @return list<string> longest first, deduplicated
     */
    private static function expand(array $secrets): array
    {
        $needles = [];

        foreach ($secrets as $secret) {
            $candidates = [$secret, ...preg_split('/\R/', $secret) ?: []];

            foreach ($candidates as $candidate) {
                $candidate = trim($candidate);

                if (strlen($candidate) < 6 || str_contains(self::PLACEHOLDERS, $candidate)) {
                    continue;
                }

                foreach ([$candidate, rawurlencode($candidate), urlencode($candidate), str_replace('/', '\\/', $candidate), base64_encode($candidate)] as $form) {
                    $needles[$form] = true;
                }
            }
        }

        $list = array_map('strval', array_keys($needles));
        usort($list, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $list;
    }
}
