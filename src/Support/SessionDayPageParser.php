<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;

/**
 * Extracts the session-day calendar from a chamber's `/session?days` page.
 *
 * There is no feed for this -- only an HTML page listing every session day of
 * the current two-year session as one button per day, grouped under month
 * headers for display. The month/year grouping is not relied on here: each
 * button's own `href` already carries the complete date
 * (`?SessDate=MM/DD/YYYY`), which is the one part of the markup a redesign is
 * least likely to change without also breaking the page's own links. A
 * day marked "NV" (verified against a live page: an "NV" day has zero roll
 * calls, floor amendments, or memos, where a plain day has real vote tallies)
 * is a Non-Voting session day -- the chamber convened without taking floor
 * action.
 */
class SessionDayPageParser
{
    private const DATE_BUTTON_PATTERN = '/<a\s+class="dateBtn[^"]*"[^>]*href="([^"]*SessDate=(\d{2})\/(\d{2})\/(\d{4}))"[^>]*>(.*?)<\/a>/is';

    /**
     * @return list<array{date: string, voting_day: bool, url: string}>
     *
     * @throws PalegisException When nothing matches -- almost certainly a page
     *                          redesign rather than a genuinely empty calendar,
     *                          since a chamber's session-day page always lists
     *                          at least the days already held this session.
     */
    public static function parse(string $html, string $baseUrl = 'https://www.palegis.us'): array
    {
        if (! preg_match_all(self::DATE_BUTTON_PATTERN, $html, $matches, PREG_SET_ORDER) || $matches === []) {
            throw new PalegisException(
                'No session days found on the page. palegis.us may have redesigned it -- '
                .'SessionDayPageParser expects an <a class="dateBtn" href="...SessDate=MM/DD/YYYY"> per day.'
            );
        }

        return array_map(
            fn (array $match): array => self::toRow($match, $baseUrl),
            $matches
        );
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}  $match
     * @return array{date: string, voting_day: bool, url: string}
     */
    private static function toRow(array $match, string $baseUrl): array
    {
        [, $href, $month, $day, $year, $label] = $match;

        $label = mb_strtoupper(trim(strip_tags($label)));

        return [
            'date' => sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day),
            // The label is the day number, plus " NV" when the source marks
            // it non-voting -- never any other suffix.
            'voting_day' => ! str_contains($label, 'NV'),
            'url' => str_starts_with($href, 'http') ? $href : $baseUrl.$href,
        ];
    }
}
