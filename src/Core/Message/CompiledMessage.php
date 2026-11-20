<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Message;

/**
 * Provider-ready output of a MessageCompiler: everything except URL and authorization, which the driver adds.
 */
final readonly class CompiledMessage
{
    /**
     * @param  array<string, string>  $headers  provider headers without authorization (e.g. apns-topic, apns-push-type)
     * @param  list<string>  $warnings  capabilities dropped in lenient mode, truncation applied, ...
     * @param  array<string, string>  $meta  compiler facts useful to the driver/logs (e.g. push_type, topic)
     */
    public function __construct(
        public array $headers,
        public string $body,
        /** Size counted against the provider limit (not necessarily strlen($body), see docs/contracts/messages.md). */
        public int $payloadBytes,
        public int $limitBytes,
        public array $warnings = [],
        public array $meta = [],
    ) {}
}
