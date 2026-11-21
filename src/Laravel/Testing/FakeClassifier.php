<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

use Padosoft\MobilePush\Core\Outcome\ClassifyContext;
use Padosoft\MobilePush\Core\Outcome\Decision;
use Padosoft\MobilePush\Core\Outcome\OutcomeClassifier;
use Padosoft\MobilePush\Core\Transport\Exchange;

/** Answers with what the test scripted instead of interpreting a provider response. @internal */
final readonly class FakeClassifier implements OutcomeClassifier
{
    public function __construct(private PushFake $fake) {}

    public function classify(Exchange $exchange, ClassifyContext $context): Decision
    {
        return $this->fake->decide($context->destination, $exchange->request->id);
    }
}
