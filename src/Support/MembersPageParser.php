<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;

/**
 * Extracts a chamber's member roster from its `/house/members` or
 * `/senate/members` page -- optionally for a past two-year session via
 * `?SessYr=YYYY` -- rather than the current-roster-only Members RSS feed.
 *
 * There is no feed for this: the Members RSS feeds ({@see LaravelPalegis::getHouseMembers()}/
 * {@see LaravelPalegis::getSenateMembers()}) always reflect who is sitting
 * today, with no way to ask for a past General Assembly. This HTML page,
 * however, carries a session picker (`?SessYr=2007`, `2009`, … up to the
 * current session) that renders that session's own roster -- same markup,
 * same photo convention (`/resources/images/members/200/{id}.jpg`), and the
 * same numeric member id used throughout the rest of this package (the id in
 * a historical roster's bio link matches the `memberId` on that same
 * person's current RSS entry, verified against a live page).
 *
 * Each member renders as one `<div class="... member ...">` grid card
 * carrying `data-name`/`data-county`/`data-party`/`data-district`/
 * `data-leadership` attributes, with the bio link/photo/display name inside.
 * Scraped rather than a stable, versioned contract -- like
 * {@see SessionDayPageParser}, a redesign of this page breaks the sync
 * silently (a parse that finds nothing) rather than failing loudly the way a
 * malformed RSS response would.
 */
class MembersPageParser
{
    private const CARD_PATTERN = '/<div class="[^"]*\bmember\b[^"]*"\s+data-name="([^"]*)"\s+data-county="([^"]*)"\s+data-party="([^"]*)"\s+data-district="([^"]*)"\s+data-leadership="([^"]*)">(.*?)<div class="thumb-info-caption[^"]*">.*?<\/div>\s*<\/span>\s*<\/div>/is';

    private const HREF_PATTERN = '/<a\s+href="([^"]*)"/i';

    private const IMAGE_PATTERN = '/<img\s+src="([^"]*)"/i';

    private const NAME_PATTERN = '/<span class="thumb-info-inner">(.*?)<\/span>/is';

    private const MEMBER_ID_PATTERN = '/\/bio\/([0-9]+)\//i';

    /**
     * @return list<array{id: string, name: string, party: string, district: string, county: string, leadership: string, image_url: string, url: string}>
     *
     * @throws PalegisException When nothing matches -- almost certainly a page
     *                          redesign rather than a genuinely empty roster.
     */
    public static function parse(string $html, string $baseUrl = 'https://www.palegis.us'): array
    {
        if (! preg_match_all(self::CARD_PATTERN, $html, $matches, PREG_SET_ORDER) || $matches === []) {
            throw new PalegisException(
                'No members found on the page. palegis.us may have redesigned it -- '
                .'MembersPageParser expects a <div class="... member ..." data-name="..." ...> per member.'
            );
        }

        return array_values(array_filter(array_map(
            fn (array $match): ?array => self::toRow($match, $baseUrl),
            $matches
        )));
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}  $match
     * @return array{id: string, name: string, party: string, district: string, county: string, leadership: string, image_url: string, url: string}|null
     */
    private static function toRow(array $match, string $baseUrl): ?array
    {
        [, $dataName, $county, $party, $district, $leadership, $body] = $match;

        if (! preg_match(self::HREF_PATTERN, $body, $hrefMatch)) {
            return null;
        }

        $href = trim($hrefMatch[1]);
        $url = str_starts_with($href, 'http') ? $href : $baseUrl.'/'.ltrim($href, '/');

        if (! preg_match(self::MEMBER_ID_PATTERN, $href, $idMatch)) {
            return null;
        }

        $name = preg_match(self::NAME_PATTERN, $body, $nameMatch)
            ? trim(html_entity_decode(strip_tags($nameMatch[1])))
            : trim($dataName);

        $imageUrl = preg_match(self::IMAGE_PATTERN, $body, $imageMatch) ? trim($imageMatch[1]) : '';

        return [
            'id' => $idMatch[1],
            'name' => $name,
            'party' => trim($party),
            'district' => trim($district),
            'county' => trim($county),
            'leadership' => trim($leadership),
            'image_url' => $imageUrl,
            'url' => $url,
        ];
    }
}
