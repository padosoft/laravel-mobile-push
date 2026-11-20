<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Fcm\Message;

use Padosoft\MobilePush\Core\Exceptions\InvalidMessageException;

/** LED settings (Discovery LightSettings / AndroidNotificationV2 light settings: identical shape). */
final class LightSettings
{
    private function __construct(
        private float $red,
        private float $green,
        private float $blue,
        private ?float $alpha,
        private string $lightOnDuration,
        private string $lightOffDuration,
    ) {}

    /**
     * @param  float  $red  0..1
     * @param  float  $green  0..1
     * @param  float  $blue  0..1
     * @param  int|float|string  $on  seconds, or a duration string such as "0.5s"
     * @param  int|float|string  $off  seconds, or a duration string such as "1s"
     *
     * @throws InvalidMessageException
     */
    public static function of(float $red, float $green, float $blue, int|float|string $on, int|float|string $off, ?float $alpha = null): self
    {
        foreach (['red' => $red, 'green' => $green, 'blue' => $blue, 'alpha' => $alpha] as $name => $component) {
            if ($component !== null && ($component < 0.0 || $component > 1.0)) {
                throw new InvalidMessageException("Light color {$name} must be between 0 and 1.");
            }
        }

        return new self($red, $green, $blue, $alpha, FcmFields::duration($on, 'light_on_duration'), FcmFields::duration($off, 'light_off_duration'));
    }

    /** @return array{color: array<string, float>, light_on_duration: string, light_off_duration: string} */
    public function toArray(): array
    {
        $color = ['red' => $this->red, 'green' => $this->green, 'blue' => $this->blue];
        if ($this->alpha !== null) {
            $color['alpha'] = $this->alpha;
        }

        return ['color' => $color, 'light_on_duration' => $this->lightOnDuration, 'light_off_duration' => $this->lightOffDuration];
    }
}
