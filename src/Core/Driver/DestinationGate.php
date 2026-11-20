<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Driver;

/**
 * A pluggable per-destination rule evaluated immediately before the network (eligibility, consent, frequency caps).
 *
 * Gates run once per window, in the order of SendOptions::$gates, after the expiry and execution-mode checks and
 * before the guards; each gate sees only the destinations the previous gates let through. A registry-backed gate
 * can therefore do one query per window. Gates are not consulted again for an in-process retry of the same send.
 *
 * A gate that throws: before anything was sent or reported, the exception propagates (a broken gate is a
 * configuration error); afterwards the window's candidates become Deferred, never sent unchecked.
 */
interface DestinationGate
{
    /**
     * @param  list<SendItem>  $items  the candidates of this window, in input order
     * @return array<int, GateDenial> denials keyed by the position in `$items`; positions not returned may proceed
     */
    public function check(GateContext $context, array $items): array;
}
