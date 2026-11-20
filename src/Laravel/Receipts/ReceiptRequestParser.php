<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Receipts;

use Illuminate\Http\Request;
use Padosoft\MobilePush\Core\Ledger\ClientEvent;
use Padosoft\MobilePush\Core\Receipts\ClientTimestamp;
use Padosoft\MobilePush\Core\Receipts\ReceiptMarker;

/**
 * Turns a request into reports, or null when its shape is wrong. Accepts one report `{marker, event, at?}`, a list
 * of reports, or `{receipts: [...]}`, capped at `$maxBatch`. A bad shape yields null with no detail: the caller
 * answers the same generic body for every failure.
 */
final class ReceiptRequestParser
{
    /** @return list<ReceiptReport>|null */
    public static function parse(Request $request, int $maxBatch): ?array
    {
        $input = $request->json()->all();
        if ($input === []) {
            $input = $request->all();
        }

        if (array_key_exists('marker', $input)) {
            $items = [$input];
        } elseif (isset($input['receipts']) && is_array($input['receipts'])) {
            $items = $input['receipts'];
        } else {
            $items = $input;
        }

        if ($items === [] || ! array_is_list($items) || count($items) > max(1, $maxBatch)) {
            return null;
        }

        $reports = [];
        foreach ($items as $item) {
            $report = is_array($item) ? self::item($item) : null;
            if ($report === null) {
                return null;
            }
            $reports[] = $report;
        }

        return $reports;
    }

    /** @param array<mixed> $item */
    private static function item(array $item): ?ReceiptReport
    {
        $marker = $item['marker'] ?? null;
        $event = $item['event'] ?? null;
        $at = $item['at'] ?? null;

        if (! is_string($marker) || $marker === '' || strlen($marker) > ReceiptMarker::MAX_LENGTH || ! is_string($event)) {
            return null;
        }
        $event = ClientEvent::tryFrom($event);
        if ($event === null) {
            return null;
        }

        $when = null;
        if ($at !== null) {
            $when = is_string($at) ? ClientTimestamp::parse($at) : null;
            if ($when === null) {
                return null;
            }
        }

        return new ReceiptReport($marker, $event, $when);
    }
}
