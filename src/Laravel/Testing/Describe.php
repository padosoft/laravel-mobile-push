<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Core\Message\Message;
use Padosoft\MobilePush\Core\Message\PushMessage;
use Padosoft\MobilePush\Core\Privacy\TokenMasker;
use Padosoft\MobilePush\Core\Transport\Request;

/**
 * Human-readable, redacted descriptions for assertion failure messages: device tokens are masked and the
 * authorization header never appears.
 *
 * @internal
 */
final class Describe
{
    public static function destination(Destination $destination): string
    {
        $kind = $destination->kind();
        // Topics and conditions are application-chosen names, not secrets; individual destinations are masked.
        $value = in_array($kind, [DestinationKind::FcmTopic, DestinationKind::FcmCondition], true)
            ? $destination->value()
            : TokenMasker::mask($destination->value());

        return $kind->value.':'.$value;
    }

    public static function message(Message $message): string
    {
        if ($message instanceof PushMessage) {
            $title = $message->title === null ? '' : sprintf(' "%s"', $message->title);

            return 'PushMessage'.$title;
        }

        return $message::class;
    }

    public static function push(SentPush $push): string
    {
        return sprintf(
            '%s -> %s on "%s" (scope "%s")%s%s',
            self::message($push->message),
            self::destination($push->destination),
            $push->connection,
            $push->scope,
            $push->queued ? ' [queued]' : '',
            $push->result === null ? '' : ' => '.$push->result->status->value,
        );
    }

    /** @param  iterable<SentPush>  $pushes */
    public static function pushes(iterable $pushes): string
    {
        $lines = [];
        foreach ($pushes as $push) {
            $lines[] = '  - '.self::push($push);
        }

        return $lines === [] ? '  (none)' : implode("\n", $lines);
    }

    public static function request(Request $request): string
    {
        $path = (string) parse_url($request->url, PHP_URL_PATH);
        if (preg_match('#^(/3/device/)(.+)$#', $path, $m) === 1) {
            $path = $m[1].TokenMasker::mask($m[2]);
        }
        $host = (string) parse_url($request->url, PHP_URL_HOST);

        return sprintf('%s https://%s%s', $request->method, $host, $path);
    }

    /** The request body for a failure message: device tokens masked, long bodies cut. */
    public static function body(Request $request): string
    {
        $decoded = json_decode($request->body, true);
        if (is_array($decoded) && is_array($decoded['message'] ?? null) && is_string($decoded['message']['token'] ?? null)) {
            $decoded['message']['token'] = TokenMasker::mask($decoded['message']['token']);
            $body = (string) json_encode($decoded);
        } else {
            $body = $request->body;
        }

        return strlen($body) > 500 ? substr($body, 0, 500).'...' : $body;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public static function headers(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[strtolower($name)] = strtolower($name) === 'authorization' ? '[redacted]' : $value;
        }

        return $out;
    }
}
