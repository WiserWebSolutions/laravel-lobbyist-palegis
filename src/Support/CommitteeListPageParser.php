<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;

/**
 * Extracts a chamber's committee list -- name and numeric code -- from its
 * `/{house|senate}/committees/committee-list` page.
 *
 * The numeric code is not published anywhere else this package reads: the
 * committee assignments/schedule RSS feeds ({@see PalegisDriver::committeeAssignments()}/
 * {@see PalegisDriver::committeeMeetings()}) identify a committee only by
 * name. This code is what a committee roll-call vote-summary URL requires
 * (`committeecode=64`) -- {@see CommitteeRollCallEnumerator} reads this list
 * first to know which codes to walk.
 *
 * Each committee renders as two links (a desktop and a mobile variant of the
 * same card), so the result is deduplicated by code.
 *
 * Scraped rather than a stable, versioned contract -- like
 * {@see MembersPageParser}/{@see SessionDayPageParser}, a redesign of this
 * page breaks the sync silently (a parse that finds nothing) rather than
 * failing loudly the way a malformed RSS response would.
 */
class CommitteeListPageParser
{
    private const LINK_PATTERN = '/href=[\'"][^\'"]*\/committees\/([0-9]+)\/([^\'"]*)[\'"]\s+class=[\'"]committee[^\'"]*[\'"]>([^<]*)<\/a>/i';

    /**
     * @return list<array{code: string, slug: string, name: string}>
     *
     * @throws PalegisException When nothing matches -- almost certainly a page
     *                          redesign, since a chamber always has committees.
     */
    public static function parse(string $html): array
    {
        if (! preg_match_all(self::LINK_PATTERN, $html, $matches, PREG_SET_ORDER) || $matches === []) {
            throw new PalegisException(
                'No committees found on the page. palegis.us may have redesigned it -- '
                .'CommitteeListPageParser expects a <a href="/{chamber}/committees/{code}/{slug}" class="committee ..."> per committee.'
            );
        }

        $seen = [];
        $committees = [];

        foreach ($matches as $match) {
            $code = $match[1];

            if (isset($seen[$code])) {
                continue;
            }

            $seen[$code] = true;

            $committees[] = [
                'code' => $code,
                'slug' => $match[2],
                'name' => trim(html_entity_decode(strip_tags($match[3]))),
            ];
        }

        return $committees;
    }
}
