<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Health;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An operational alert. Title, body and context are redacted by OpsAlerter before they get here; this class only
 * formats them. Not queued: it is sent from a scheduler/worker process and a failure must be visible to the sender.
 */
final class OpsAlert extends Notification
{
    /**
     * @param  list<string>  $channels
     * @param  array<array-key, mixed>  $context  already redacted
     */
    public function __construct(
        public readonly AlertKind $kind,
        public readonly string $key,
        public readonly string $title,
        public readonly string $body,
        public readonly array $context = [],
        private readonly array $channels = ['mail'],
    ) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('[mobile-push] '.$this->title)->line($this->body);
        foreach ($this->context as $name => $value) {
            if (is_scalar($value)) {
                $mail->line($name.': '.(string) $value);
            }
        }

        return $mail->line('Alert key: '.$this->key);
    }

    /** @return array{text: string} */
    public function toSlackWebhook(): array
    {
        $text = '*[mobile-push] '.$this->title."*\n".$this->body;
        foreach ($this->context as $name => $value) {
            if (is_scalar($value)) {
                $text .= "\n".$name.': '.(string) $value;
            }
        }

        return ['text' => $text];
    }
}
