<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Audience;

use Padosoft\MobilePush\Core\Exceptions\ValidationException;
use Padosoft\MobilePush\Core\Identity\Destination;
use Padosoft\MobilePush\Core\Identity\DestinationKind;
use Padosoft\MobilePush\Laravel\Campaigns\CampaignException;
use Padosoft\MobilePush\Laravel\Devices\PushDevice;

/**
 * An import file of typed destinations, read lazily: one destination per line, `<kind>,<value>`, where kind is a
 * DestinationKind value (`apns_device_token`, `fcm_token`, `fcm_topic`, ...). The kind is always explicit; it is
 * never guessed from the value. Empty lines and lines starting with `#` are ignored; a malformed line is counted as
 * invalid and skipped, it never stops the campaign.
 *
 * Order and bound: file order; the cursor is a byte offset and the upper bound is the file size when the campaign
 * starts, so lines appended later are not part of the campaign. The file must stay readable at the same path by
 * every worker until planning ends (shared storage), and must not be rewritten in place meanwhile.
 */
final readonly class DestinationFileAudience implements Audience
{
    public const CURSOR = 'b';

    /** Longest accepted line (an FCM condition is at most 2048 characters). */
    private const MAX_LINE = 8192;

    public function __construct(public string $path)
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('An audience file needs a path.');
        }
    }

    public function toArray(): array
    {
        return ['path' => $this->path];
    }

    public static function fromArray(array $parameters): static
    {
        $path = $parameters['path'] ?? null;

        return new self(is_string($path) ? $path : '');
    }

    public function upperBound(AudienceContext $context): string
    {
        clearstatcache(true, $this->path);
        $size = is_file($this->path) ? filesize($this->path) : false;
        if ($size === false) {
            throw new CampaignException('The audience file is not readable.');
        }

        return KeysetCursor::encode(self::CURSOR, $size);
    }

    public function slice(AudienceContext $context, ?string $cursor, string $upperBound, int $limit): AudienceSlice
    {
        $offset = KeysetCursor::decode(self::CURSOR, $cursor);
        $upper = KeysetCursor::decode(self::CURSOR, $upperBound);
        if ($offset >= $upper) {
            return new AudienceSlice([], KeysetCursor::encode(self::CURSOR, $upper), true);
        }

        $handle = is_file($this->path) ? @fopen($this->path, 'rb') : false;
        if ($handle === false) {
            throw new CampaignException('The audience file is not readable.');
        }

        try {
            if (fseek($handle, $offset) !== 0) {
                throw new CampaignException('The audience file is shorter than the stored cursor.');
            }

            $destinations = [];
            $invalid = 0;
            $position = $offset;
            while (count($destinations) < $limit && $position < $upper) {
                $line = fgets($handle, self::MAX_LINE + 2);
                if ($line === false) {
                    break;
                }
                $end = $position + strlen($line);
                if ($end > $upper) {
                    // A line that crosses the bound was (at least partly) written after the start: not included.
                    $position = $upper;

                    break;
                }
                $position = $end;
                $text = trim($line);
                if ($text === '' || str_starts_with($text, '#')) {
                    continue;
                }
                $destination = self::parse($text);
                if ($destination === null) {
                    $invalid++;

                    continue;
                }
                $destinations[] = $destination;
            }
        } finally {
            fclose($handle);
        }

        return new AudienceSlice($destinations, KeysetCursor::encode(self::CURSOR, $position), $position >= $upper, $invalid);
    }

    public function reach(): AudienceReach
    {
        return AudienceReach::Mixed;
    }

    private static function parse(string $line): ?Destination
    {
        $comma = strpos($line, ',');
        if ($comma === false) {
            return null;
        }
        $kind = DestinationKind::tryFrom(trim(substr($line, 0, $comma)));
        if ($kind === null) {
            return null;
        }

        try {
            return PushDevice::build($kind, trim(substr($line, $comma + 1)));
        } catch (ValidationException|\InvalidArgumentException) {
            return null;
        }
    }
}
