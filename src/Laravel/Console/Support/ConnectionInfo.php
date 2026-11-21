<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

use Padosoft\MobilePush\Core\Credentials\Finding;
use Padosoft\MobilePush\Core\Credentials\Severity;
use Padosoft\MobilePush\Core\Identity\Provider;

/** Everything the console can say about one configured connection. Holds no secret. */
final readonly class ConnectionInfo
{
    /**
     * @param  list<Finding>  $findings  credential checks (messages carry the remedy)
     * @param  list<string>  $notes  things that cannot be verified from here
     * @param  array{allow: bool, only_to: int, allow_topics: bool, allow_broadcast: bool, allow_channel_management: bool}  $live
     */
    public function __construct(
        public string $name,
        public ?Provider $provider,
        public ?string $appId,
        public ?string $environment,
        public ?string $auth,
        public ?string $identity,
        public ?string $generation,
        public ?int $daysLeft,
        public array $findings,
        public array $notes,
        public ?string $error,
        public array $live,
        public bool $hasSecondary,
    ) {}

    public function hasErrors(): bool
    {
        if ($this->error !== null) {
            return true;
        }
        foreach ($this->findings as $finding) {
            if ($finding->severity === Severity::Error) {
                return true;
            }
        }

        return false;
    }
}
