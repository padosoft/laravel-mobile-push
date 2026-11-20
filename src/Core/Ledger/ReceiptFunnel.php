<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Core\Ledger;

/**
 * The receipts funnel of an effect or a campaign: accepted -> reported received -> reported opened, always with its
 * denominator and coverage. Reading rules:
 *  - "reported" means the app said so; it is not proof of delivery or of a human reading.
 *  - A missing report is NOT OBSERVED. Nothing here says "not delivered".
 *  - `acceptedWithMarker` is how many accepted deliveries were even able to report (they carried a marker). Topic,
 *    condition and broadcast sends never carry one.
 *  - Unknown outcomes later confirmed by a report are a separate bucket: the provider outcome is untouched.
 */
final readonly class ReceiptFunnel
{
    public function __construct(
        /** Deliveries the provider accepted. The denominator. */
        public int $accepted = 0,
        /** Of those, how many carried a receipt marker (could report). */
        public int $acceptedWithMarker = 0,
        /** Accepted deliveries with a client-reported `received`. */
        public int $reportedReceived = 0,
        /** Accepted deliveries with a client-reported `opened` (independent of `received`). */
        public int $reportedOpened = 0,
        /** Deliveries whose outcome is unknown (response lost). */
        public int $unknownOutcome = 0,
        /** Unknown-outcome deliveries with a client-reported `received`. */
        public int $unknownReportedReceived = 0,
        /** Unknown-outcome deliveries with a client-reported `opened`. */
        public int $unknownReportedOpened = 0,
        /** Unknown-outcome deliveries with at least one client report (the push did reach the phone). */
        public int $unknownClientConfirmed = 0,
    ) {}

    /** Accepted deliveries that could not report at all (no marker). */
    public function acceptedWithoutMarker(): int
    {
        return max(0, $this->accepted - $this->acceptedWithMarker);
    }

    /** Able to report but no `received` observed. NOT "not delivered". */
    public function receivedNotObserved(): int
    {
        return max(0, $this->acceptedWithMarker - $this->reportedReceived);
    }

    /** "Accepted or client-confirmed": provider accepted plus unknown outcomes the phone confirmed. */
    public function acceptedOrClientConfirmed(): int
    {
        return $this->accepted + $this->unknownClientConfirmed;
    }

    /** Share of accepted deliveries able to report. Null when nothing was accepted. */
    public function markerCoverage(): ?float
    {
        return self::ratio($this->acceptedWithMarker, $this->accepted);
    }

    /** Reported received / able to report. The honest rate; a lower bound of the real one. */
    public function receivedRate(): ?float
    {
        return self::ratio($this->reportedReceived, $this->acceptedWithMarker);
    }

    public function openedRate(): ?float
    {
        return self::ratio($this->reportedOpened, $this->acceptedWithMarker);
    }

    /** Reported received / all accepted (includes deliveries that could not report). */
    public function receivedOfAccepted(): ?float
    {
        return self::ratio($this->reportedReceived, $this->accepted);
    }

    public function openedOfAccepted(): ?float
    {
        return self::ratio($this->reportedOpened, $this->accepted);
    }

    /** @return array<string, int|float|null> */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'accepted_with_marker' => $this->acceptedWithMarker,
            'accepted_without_marker' => $this->acceptedWithoutMarker(),
            'reported_received' => $this->reportedReceived,
            'reported_opened' => $this->reportedOpened,
            'received_not_observed' => $this->receivedNotObserved(),
            'unknown_outcome' => $this->unknownOutcome,
            'unknown_reported_received' => $this->unknownReportedReceived,
            'unknown_reported_opened' => $this->unknownReportedOpened,
            'unknown_client_confirmed' => $this->unknownClientConfirmed,
            'accepted_or_client_confirmed' => $this->acceptedOrClientConfirmed(),
            'marker_coverage' => $this->markerCoverage(),
            'received_rate' => $this->receivedRate(),
            'opened_rate' => $this->openedRate(),
        ];
    }

    /**
     * Fold stored rows (column layout of the deliveries table) into a funnel.
     *
     * @param  iterable<array<string, mixed>>  $rows
     */
    public static function fromRows(iterable $rows): self
    {
        $n = array_fill_keys(['a', 'm', 'r', 'o', 'u', 'ur', 'uo', 'uc'], 0);
        foreach ($rows as $row) {
            $state = Row::str($row, 'state');
            $received = Row::intOrNull($row, 'client_received_at') !== null;
            $opened = Row::intOrNull($row, 'client_opened_at') !== null;
            if ($state === DeliveryState::Accepted->value) {
                $n['a']++;
                $n['m'] += (int) (Row::bool($row, 'receipt_marker') || $received || $opened);
                $n['r'] += (int) $received;
                $n['o'] += (int) $opened;
            } elseif ($state === DeliveryState::OutcomeUnknown->value) {
                $n['u']++;
                $n['ur'] += (int) $received;
                $n['uo'] += (int) $opened;
                $n['uc'] += (int) ($received || $opened);
            }
        }

        return new self($n['a'], $n['m'], $n['r'], $n['o'], $n['u'], $n['ur'], $n['uo'], $n['uc']);
    }

    private static function ratio(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : $part / $whole;
    }
}
