<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns\Message;

use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;

/** The `aps.sound` value: a file name string, or the critical-alert dictionary. */
final readonly class Sound
{
    private function __construct(
        public string $name,
        public bool $critical = false,
        public ?float $volume = null,
    ) {}

    public static function default(): self
    {
        return new self('default');
    }

    public static function named(string $name): self
    {
        return new self($name);
    }

    /**
     * @param  float  $volume  0 (silent) to 1 (full volume)
     *
     * @throws InvalidMessageException volume out of range
     */
    public static function critical(string $name = 'default', float $volume = 1.0): self
    {
        if ($volume < 0.0 || $volume > 1.0) {
            throw new InvalidMessageException('A critical sound volume must be between 0 and 1.');
        }

        return new self($name, true, $volume);
    }

    /** @return string|array{critical: int, name: string, volume?: float} */
    public function toValue(): string|array
    {
        if (! $this->critical) {
            return $this->name;
        }

        $value = ['critical' => 1, 'name' => $this->name];
        if ($this->volume !== null) {
            $value['volume'] = $this->volume;
        }

        return $value;
    }
}
