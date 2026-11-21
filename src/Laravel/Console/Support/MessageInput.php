<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

use Illuminate\Console\Command;
use Padosoft\MobilePush\Core\Message\PushMessage;

/** The message options shared by send, preview and simulator, and the PushMessage they build. */
final class MessageInput
{
    public const SIGNATURE = '
        {--title= : Notification title}
        {--body= : Notification body}
        {--data=* : Custom data as key=value (repeatable)}
        {--badge= : Badge number (0 or more)}
        {--sound= : Sound name ("default" for the system sound)}
        {--type=alert : alert (visible notification) or silent (background / data-only)}';

    /** @throws ConsoleInputException */
    public static function build(Command $command): PushMessage
    {
        $type = strtolower(self::string($command->option('type')) ?? 'alert');
        if (! in_array($type, ['alert', 'silent', 'background'], true)) {
            throw new ConsoleInputException(sprintf('Unknown --type "%s". Use "alert" for a visible notification or "silent" for a background push.', $type));
        }

        $message = PushMessage::make(self::string($command->option('title')), self::string($command->option('body')));
        if ($type !== 'alert') {
            $message->silent();
        }

        $badge = self::string($command->option('badge'));
        if ($badge !== null) {
            if (preg_match('/^\d{1,9}$/', $badge) !== 1) {
                throw new ConsoleInputException('--badge must be a whole number of 0 or more.');
            }
            $message->badge((int) $badge);
        }

        $sound = self::string($command->option('sound'));
        if ($sound !== null) {
            $message->sound($sound);
        }

        $data = [];
        $entries = $command->option('data');
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            $position = strpos($entry, '=');
            if ($position === false || $position === 0) {
                throw new ConsoleInputException(sprintf('--data "%s" is not key=value. Example: --data=order=42', $entry));
            }
            $data[substr($entry, 0, $position)] = substr($entry, $position + 1);
        }
        if ($data !== []) {
            $message->data($data);
        }

        return $message;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
