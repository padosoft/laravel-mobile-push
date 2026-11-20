<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Policy;

/**
 * The binding's live-sending settings (config `live.*`) for non-production environments.
 *
 * The allowlist holds destination INDEXES (see TokenIndex::index), never raw tokens.
 */
final readonly class LiveSettings
{
    /**
     * @param  list<string>  $onlyTo  allowlisted destination indexes
     */
    public function __construct(
        public bool $allow = false,
        public array $onlyTo = [],
        public bool $allowTopics = false,
        public bool $allowBroadcast = false,
        public bool $allowChannelManagement = false,
    ) {}
}
