<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Apns\Channels;

/** The difference between the channels APNs lists (`all()`) and the ones the application remembers. */
final readonly class ChannelReconciliation
{
    /**
     * @param  list<string>  $unknownLocally  exist at APNs, absent from the local list (typically a create whose response was lost)
     * @param  list<string>  $missingRemotely  in the local list, no longer listed by APNs (deleted or never created)
     */
    public function __construct(
        public array $unknownLocally,
        public array $missingRemotely,
    ) {}

    public function isConsistent(): bool
    {
        return $this->unknownLocally === [] && $this->missingRemotely === [];
    }
}
