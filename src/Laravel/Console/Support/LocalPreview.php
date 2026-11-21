<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Console\Support;

use Padosoft\MobilePush\Core\Message\CompiledMessage;

/** The result of compiling a message locally for one placeholder destination. */
final readonly class LocalPreview
{
    /**
     * @param  list<string>  $appCodeNotes  capabilities used that work only when the app does its part
     * @param  list<string>  $unsupported  capabilities used that this platform cannot provide
     */
    public function __construct(
        public string $provider,
        public string $platform,
        public string $bindingLabel,
        public bool $placeholderBinding,
        public string $destinationLabel,
        public CompiledMessage $compiled,
        public array $appCodeNotes,
        public array $unsupported,
    ) {}
}
