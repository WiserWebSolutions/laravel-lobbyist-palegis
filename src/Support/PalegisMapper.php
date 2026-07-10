<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Data\Legislator;
use WiserWebSolutions\Lobbyist\Data\Session;
use WiserWebSolutions\Lobbyist\Data\Vote;
use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Enums\StateEnum;

/**
 * Translates parsed palegis.us feeds (RSS items and Bill History records) into
 * normalized core DTOs.
 *
 * This is the only place that knows the shape of the PA feeds, so core stays
 * unaware of any specific data source.
 */
class PalegisMapper
{
    /**
     * Map a Bill History Data record (see LaravelPalegis::getBillHistory()) to
     * a full-detail Bill for a single-bill lookup, preserving the raw record
     * (sponsors, full action history, full printer-number history) since
     * only one record is materialized at a time.
     */
    public static function billFromHistory(array $record): Bill
    {
        return self::billDto($record, includeRaw: true);
    }

    /**
     * Map a Bill History Data record to a lightweight-summary Bill, for
     * listing every bill in a session at once. Omits the raw record
     * (sponsors, complete action history, complete printer-number history)
     * — a session can hold thousands of bills, and retaining full detail on
     * every one of them when only the summary fields are needed is the
     * majority of the memory cost of listing them all.
     */
    public static function billSummaryFromHistory(array $record): Bill
    {
        return self::billDto($record, includeRaw: false);
    }

    private static function billDto(array $record, bool $includeRaw): Bill
    {
        $lastAction = ! empty($record['actions']) ? end($record['actions']) : null;
        $lastPrinters = ! empty($record['printers_numbers']) ? end($record['printers_numbers']) : null;

        $meta = [
            'id' => $record['id'] ?? '',
            'number' => $record['designator'] ?? '',
            'title' => $record['short_title'] ?? '',
            'description' => $record['short_title'] ?? '',
            'state' => StateEnum::PA,
            'chamber' => Chamber::fromString($record['body'] ?? null),
            'last_action' => $lastAction['full_action'] ?? '',
            'last_action_date' => $lastAction['date'] ?? null,
            'url' => $lastPrinters['pdf_url'] ?? '',
        ];

        if ($includeRaw) {
            $meta['raw'] = $record;
        }

        return new Bill(meta: $meta);
    }

    public static function vote(array $item, Chamber $chamber): Vote
    {
        return new Vote(meta: [
            'id' => $item['guid'] ?? $item['link'] ?? ($item['title'] ?? ''),
            'chamber' => $chamber,
            'date' => $item['pub_date'] ?? null,
            'description' => $item['title'] ?? $item['description'] ?? '',
            'url' => $item['link'] ?? '',
            'raw' => $item,
        ]);
    }

    public static function legislator(array $item, Chamber $chamber): Legislator
    {
        return new Legislator(meta: [
            'id' => $item['guid'] ?? $item['link'] ?? ($item['title'] ?? ''),
            'name' => $item['title'] ?? '',
            'chamber' => $chamber,
            'role' => $item['description'] ?? null,
            'state' => StateEnum::PA,
            'url' => $item['link'] ?? '',
            'raw' => $item,
        ]);
    }

    /**
     * The RSS feeds always target the current General Assembly; expose a single
     * synthetic session for PA.
     */
    public static function currentSession(): Session
    {
        return new Session(meta: [
            'state' => StateEnum::PA,
            'name' => 'Pennsylvania General Assembly',
            'title' => 'Pennsylvania General Assembly (current)',
        ]);
    }
}
