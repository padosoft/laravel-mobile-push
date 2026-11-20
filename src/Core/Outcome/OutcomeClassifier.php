<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Outcome;

use Padosoft\MobilePush\Core\Transport\Exchange;

/**
 * The ONLY place where a provider answer (or a transport failure) is interpreted.
 *
 * Pure and total: every possible Exchange maps to exactly one Decision, unknown provider strings included
 * (ReasonCode::Unknown with the raw string preserved). It never throws and never performs side effects:
 * quarantine, invalidation, token refresh and breakers react to the returned Decision.
 */
interface OutcomeClassifier
{
    public function classify(Exchange $exchange, ClassifyContext $context): Decision;
}
