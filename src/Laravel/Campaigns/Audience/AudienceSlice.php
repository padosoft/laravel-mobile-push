<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Campaigns\Audience;

use Padosoft\MobilePush\Core\Identity\Destination;

/** One page of an audience. */
final readonly class AudienceSlice
{
    /**
     * @param  list<Destination>  $destinations
     */
    public function __construct(
        public array $destinations,
        /** Where the next slice starts. Equal to the input cursor when nothing was read. */
        public ?string $nextCursor,
        /** True when the upper bound was reached: there is no next slice. */
        public bool $exhausted,
        /** Input entries that could not be turned into a destination (e.g. a malformed line of an import file). */
        public int $invalid = 0,
    ) {}
}
