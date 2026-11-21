<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Driver;

/**
 * Optional companion of SendObserver for a per-call observer (SendOptions::withObserver()) that must know the exact
 * moment requests leave: the durable relay marks "attempt started" in its ledger here, for exactly the requests that
 * are about to be written, not for the items still waiting in the Sender's window (W5-P).
 *
 * beforeTransmit() is called once per pass (the first attempt of a window and every in-process retry), after the
 * gates, the guards, compilation and the bearer, immediately before Transport::send(), with the items whose requests
 * are built, keyed by request id. It returns the request ids that must NOT be sent (for example a lease that was
 * lost): those items are not handed to the transport and get one result, Skipped(Cancelled) (OutcomeUnknown when an
 * earlier attempt of this send may have been accepted). Unlike the other observer callbacks it is not isolated: an
 * exception aborts the send before any request of this pass is handed over (the report throws), because an
 * observer that could not record the start must not let the request leave unrecorded.
 *
 * Existing SendObserver implementations are unaffected: only observers that also implement this interface are asked.
 */
interface TransmitObserver
{
    /**
     * @param  array<string, SendItem>  $items  request id => item, in the order they will be sent
     * @return list<string> request ids to withhold
     */
    public function beforeTransmit(array $items): array;
}
