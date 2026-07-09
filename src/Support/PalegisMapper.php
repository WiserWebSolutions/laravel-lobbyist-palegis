<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Data\Legislator;
use WiserWebSolutions\Lobbyist\Data\Session;
use WiserWebSolutions\Lobbyist\Data\Vote;
use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Enums\StateEnum;

/**
 * Translates parsed palegis.us RSS items into normalized core DTOs.
 *
 * This is the only place that knows the shape of the PA RSS feeds, so core
 * stays unaware of any specific data source.
 */
class PalegisMapper
{
    public static function bill(array $item, Chamber $chamber): Bill
    {
        $title = (string) ($item['title'] ?? '');

        return new Bill(meta: [
            'id' => $item['guid'] ?? $item['link'] ?? $title,
            'number' => self::extractBillNumber($title)
                ?? self::extractBillNumber((string) ($item['link'] ?? ''))
                ?? '',
            'title' => $title,
            'description' => $item['description'] ?? '',
            'state' => StateEnum::PA,
            'chamber' => $chamber,
            'last_action' => $item['description'] ?? '',
            'last_action_date' => $item['pub_date'] ?? null,
            'url' => $item['link'] ?? '',
            'raw' => $item,
        ]);
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

    /**
     * Pull a bill number like "HB1234" / "SR 12" out of arbitrary text.
     */
    private static function extractBillNumber(string $text): ?string
    {
        if (preg_match('/\b([HS][BR])\s?0*(\d+)\b/i', $text, $matches)) {
            return strtoupper($matches[1]).$matches[2];
        }

        return null;
    }
}
