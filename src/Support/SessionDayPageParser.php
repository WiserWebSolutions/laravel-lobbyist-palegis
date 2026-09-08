<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;

/**
 * Extracts the session-day calendar from a chamber's `/session?days` page.
 *
 * There is no feed for this -- only an HTML page listing every session day of
 * the current two-year session as one button per day, grouped under month
 * headers for display. The month/year grouping is not relied on here: each
 * button's own `aria-label` already carries the complete date
 * ("link for MM/DD/YYYY"), which is present on every date button regardless
 * of its other markup.
 *
 * A day already held (or otherwise confirmed) renders as `<a class="dateBtn"
 * href="...?SessDate=MM/DD/YYYY">`, linking to that day's own info page. A
 * day scheduled further out but not yet confirmed/published renders instead
 * as `<button class="dateBtn" ... disabled>` with no `href` at all -- verified
 * against a live page where September/October/November dates months ahead
 * were all disabled buttons while nearer dates were real links. Relying on
 * `href` alone (an earlier version of this parser did) silently dropped every
 * one of those not-yet-confirmed future days, which is exactly the case a
 * caller building a forward-looking calendar cares about most. `url` is
 * therefore only ever populated for the linked (`<a>`) form; a disabled
 * button's day has no page to link to yet.
 *
 * A day marked "NV" (verified against a live page: an "NV" day has zero roll
 * calls, floor amendments, or memos, where a plain day has real vote tallies)
 * is a Non-Voting session day -- the chamber convened without taking floor
 * action.
 */
class SessionDayPageParser
{
    private const DATE_BUTTON_PATTERN = '/<(a|button)\s+class="dateBtn[^"]*"([^>]*)>(.*?)<\/\1>/is';

    private const ARIA_LABEL_PATTERN = '/aria-label="link for (\d{2})\/(\d{2})\/(\d{4})"/i';

    private const HREF_PATTERN = '/href="([^"]*)"/i';

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
                .'SessionDayPageParser expects a <a>/<button class="dateBtn" aria-label="link for MM/DD/YYYY"> per day.'
            );
        }

        return array_values(array_filter(array_map(
            fn (array $match): ?array => self::toRow($match, $baseUrl),
            $matches
        )));
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string}  $match  [full tag, tag name, attributes, inner text]
     * @return array{date: string, voting_day: bool, url: string}|null Null when the
     *                                                                 button carries no recognizable date -- markup this parser doesn't understand,
     *                                                                 skipped rather than guessed at.
     */
    private static function toRow(array $match, string $baseUrl): ?array
    {
        [, , $attributes, $label] = $match;

        if (! preg_match(self::ARIA_LABEL_PATTERN, $attributes, $dateMatch)) {
            return null;
        }

        [, $month, $day, $year] = $dateMatch;

        $url = '';

        if (preg_match(self::HREF_PATTERN, $attributes, $hrefMatch)) {
            $href = $hrefMatch[1];
            $url = str_starts_with($href, 'http') ? $href : $baseUrl.$href;
        }

        $label = mb_strtoupper(trim(strip_tags($label)));

        return [
            'date' => sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day),
            // The label is the day number, plus " NV" when the source marks
            // it non-voting -- never any other suffix.
            'voting_day' => ! str_contains($label, 'NV'),
            'url' => $url,
        ];
    }
}
