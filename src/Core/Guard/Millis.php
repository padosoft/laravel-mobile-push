<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Guard;

/** @internal */
final class Millis
{
    public static function of(\DateTimeInterface $at): int
    {
        return $at->getTimestamp() * 1000 + intdiv((int) $at->format('u'), 1000);
    }

    public static function toDate(int $ms): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@'.intdiv($ms, 1000)))
            ->modify(sprintf('+%d microseconds', ($ms % 1000) * 1000));
    }
}
