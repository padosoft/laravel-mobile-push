<?php

declare(strict_types=1);

use Padosoft\MobilePush\Core\Identity\Platform;
use Padosoft\MobilePush\Core\Policy\CapabilityMatrix;
use Padosoft\MobilePush\Core\Policy\Support;

it('covers every capability on both platforms and renders Markdown', function () {
    $m = new CapabilityMatrix;

    foreach (CapabilityMatrix::CAPABILITIES as $c) {
        expect($m->support($c, Platform::Ios))->not->toBeNull()->and($m->support($c, Platform::Android))->not->toBeNull();
    }

    expect($m->support('subtitle', Platform::Android)->support)->toBe(Support::Unavailable)
        ->and($m->support('image', Platform::Ios)->support)->toBe(Support::RequiresAppCode)
        ->and($m->unavailableOn(Platform::Android))->toContain('live_activity')
        ->and($m->toMarkdown())->toContain('| `title` | native')->and(substr_count($m->toMarkdown(), "\n"))->toBe(20);
});
