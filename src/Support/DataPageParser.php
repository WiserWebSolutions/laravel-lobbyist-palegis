<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;

/**
 * Extracts the list of published Bill History Data archives from
 * `https://www.palegis.us/data`.
 *
 * Each row of the `#billHistoryDataTable` links to one session's archive
 * (`/data/file?documentType=BillHistoryData&session=2025_0`) and carries a
 * `data-last-updated` attribute -- the export's own rebuild timestamp, which
 * is the natural revision marker for the whole session archive (as opposed to
 * a bill's own `lastUpdate`, which marks that one bill; see
 * {@see PalegisMapper::billFromHistory()}). Sessions go back to 1969 and the
 * current one is rebuilt hourly on weekdays.
 *
 * Scraped rather than a stable, versioned contract -- like
 * {@see MembersPageParser}/{@see SessionDayPageParser}, a redesign of this
 * page breaks the sync silently (a parse that finds nothing) rather than
 * failing loudly the way a malformed RSS response would.
 */
class DataPageParser
{
    private const ROW_PATTERN = '/<a\s+href="([^"]*documentType=BillHistoryData[^"]*)"\s+data-last-updated="([^"]*)">\s*(.*?)\s*<\/a>/is';

    private const SESSION_PATTERN = '/[?&]session=([0-9]+_[0-9]+)/i';

    private const YEAR_RANGE_PATTERN = '/^(\d{4})-(\d{4})/';

    /**
     * @return list<array{session: string, name: string, year_start: ?int, year_end: ?int, last_updated: ?string}>
     *
     * @throws PalegisException When nothing matches -- almost certainly a page
     *                          redesign rather than genuinely no published archives,
     *                          since palegis.us always lists sessions back to 1969.
     */
    public static function parse(string $html): array
    {
        if (! preg_match_all(self::ROW_PATTERN, $html, $matches, PREG_SET_ORDER) || $matches === []) {
            throw new PalegisException(
                'No Bill History Data sessions found on the page. palegis.us may have redesigned '
                .'it -- DataPageParser expects a <a href="...documentType=BillHistoryData...session=..." '
                .'data-last-updated="..."> per session.'
            );
        }

        return array_values(array_filter(array_map(
            fn (array $match): ?array => self::toRow($match),
            $matches
        )));
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string}  $match
     * @return array{session: string, name: string, year_start: ?int, year_end: ?int, last_updated: ?string}|null
     */
    private static function toRow(array $match): ?array
    {
        [, $href, $lastUpdated, $name] = $match;

        // The href arrives HTML-entity-encoded ("...&amp;session=2025_0"),
        // since this is a raw attribute value, not yet-decoded text.
        $href = html_entity_decode($href);

        if (! preg_match(self::SESSION_PATTERN, $href, $sessionMatch)) {
            return null;
        }

        $name = trim(html_entity_decode(strip_tags($name)));

        [$yearStart, $yearEnd] = preg_match(self::YEAR_RANGE_PATTERN, $name, $yearMatch)
            ? [(int) $yearMatch[1], (int) $yearMatch[2]]
            : [null, null];

        return [
            'session' => $sessionMatch[1],
            'name' => $name,
            'year_start' => $yearStart,
            'year_end' => $yearEnd,
            'last_updated' => trim($lastUpdated) !== '' ? trim($lastUpdated) : null,
        ];
    }
}
