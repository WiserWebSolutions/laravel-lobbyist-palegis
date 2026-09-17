<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;

/**
 * Extracts one floor roll call -- its date, bill, action, tallies, and every
 * member's individual position -- from a chamber's
 * `/{house|senate}/roll-calls/summary?sessYr=...&sessInd=...&rcNum=N` page.
 *
 * There is no feed for this: the RSS votes feeds
 * ({@see LaravelPalegis::getHouseVotes()}/{@see LaravelPalegis::getSenateVotes()})
 * list only the last ~100 roll calls, with a tally in free text and no
 * per-member detail at all. This page is what {@see RollCallEnumerator}
 * walks by number (`rcNum=1, 2, 3, ...`) to build a chamber's complete
 * floor-vote history, including who voted which way.
 *
 * Bill linkage comes from the page's own "Details for RCS No. N" panel
 * (`<a href="/legislation/bills/2025/hb1042">`), not from parsing a title
 * string -- the bill is a real link the page already carries, broken into
 * its year/body/type/number parts so the caller can rebuild whatever bill-id
 * format its session uses (see {@see PalegisMapper::voteFromRollCall()}). A
 * procedural roll call with no associated bill (a Master Roll Call, a
 * quorum call) has no such link, in which case `bill` is null. The session
 * year/index come from that same panel's "Vote Date" link, which always
 * carries both.
 *
 * Scraped rather than a stable, versioned contract -- like
 * {@see MembersPageParser}/{@see SessionDayPageParser}, a redesign of this
 * page breaks the sync silently (a parse that finds no members) rather than
 * failing loudly the way a malformed RSS response would.
 */
class RollCallPageParser
{
    private const MEMBER_PATTERN = '/<div class="rc-member\b[^"]*"[^>]*>(.*?)<div class="rc-member-print/is';

    private const MEMBER_ID_PATTERN = '/\/bio\/([0-9]+)\//i';

    private const NAME_PATTERN = '/<a[^>]*>\s*(?:Rep\.|Sen\.)?\s*([^<]*?)\s*<\/a>/is';

    private const PARTY_PATTERN = '/badge bg-party-([A-Z])"/i';

    private const DISTRICT_PATTERN = '/District&nbsp;([0-9]+)/i';

    private const POSITION_PATTERN = '/badge text-bg-[a-z-]+"\s+title="([^"]*)"/i';

    private const DATE_PATTERN = '/roll-calls\?sessYr=([0-9]+)&sessInd=([0-9]+)&date=([0-9]{4}-[0-9]{2}-[0-9]{2})"[^>]*>[^<]*<\/a>\s*([0-9]{1,2}:[0-9]{2}\s*[AP]M)?/i';

    private const BILL_PATTERN = '/\/legislation\/bills\/([0-9]+)\/([a-z])([a-z])([0-9]+)"/i';

    private const ACTION_PATTERN = '/Action<\/div>\s*(?:\r?\n\s*)*<div>\s*([^<]*?)\s*<\/div>/is';

    private const TALLY_PATTERN = '/(Yea|Nay|No Vote|Leave)\s*<\/div>\s*<div>\s*([0-9]+)\s*<\/div>/i';

    /**
     * @return array{session_year: ?string, session_index: ?string, date: ?string, time: ?string, bill: ?array{year: string, body: string, type: string, number: string}, action: ?string, tallies: array<string, int>, positions: list<array{id: string, name: string, party: string, district: string, position: string}>}
     *
     * @throws PalegisException When no member rows are found -- almost
     *                          certainly a page redesign, since every real floor roll call has at
     *                          least a handful of members.
     */
    public static function parse(string $html): array
    {
        if (! preg_match_all(self::MEMBER_PATTERN, $html, $matches, PREG_SET_ORDER) || $matches === []) {
            throw new PalegisException(
                'No members found on the roll call page. palegis.us may have redesigned it -- '
                .'RollCallPageParser expects a <div class="rc-member ..."> per member.'
            );
        }

        [$sessionYear, $sessionIndex, $date, $time] = self::dateParts($html);

        return [
            'session_year' => $sessionYear,
            'session_index' => $sessionIndex,
            'date' => $date,
            'time' => $time,
            'bill' => self::bill($html),
            'action' => self::action($html),
            'tallies' => self::tallies($html),
            'positions' => array_values(array_filter(array_map(
                fn (array $match): ?array => self::toPosition($match[1]),
                $matches
            ))),
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string}
     */
    private static function dateParts(string $html): array
    {
        if (! preg_match(self::DATE_PATTERN, $html, $match)) {
            return [null, null, null, null];
        }

        return [
            $match[1],
            $match[2],
            $match[3],
            isset($match[4]) && trim($match[4]) !== '' ? trim($match[4]) : null,
        ];
    }

    /**
     * @return array{year: string, body: string, type: string, number: string}|null
     */
    private static function bill(string $html): ?array
    {
        if (! preg_match(self::BILL_PATTERN, $html, $match)) {
            return null;
        }

        return [
            'year' => $match[1],
            'body' => strtoupper($match[2]),
            'type' => strtoupper($match[3]),
            'number' => $match[4],
        ];
    }

    private static function action(string $html): ?string
    {
        return preg_match(self::ACTION_PATTERN, $html, $match) && trim($match[1]) !== ''
            ? trim($match[1])
            : null;
    }

    /**
     * @return array<string, int>
     */
    private static function tallies(string $html): array
    {
        if (! preg_match_all(self::TALLY_PATTERN, $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $tallies = [];

        foreach ($matches as $match) {
            $key = match (strtolower($match[1])) {
                'yea' => 'yea',
                'nay' => 'nay',
                'no vote' => 'no_vote',
                'leave' => 'leave',
                default => null,
            };

            if ($key !== null) {
                $tallies[$key] = (int) $match[2];
            }
        }

        return $tallies;
    }

    /**
     * @return array{id: string, name: string, party: string, district: string, position: string}|null
     */
    private static function toPosition(string $body): ?array
    {
        if (! preg_match(self::MEMBER_ID_PATTERN, $body, $idMatch)) {
            return null;
        }

        if (! preg_match(self::POSITION_PATTERN, $body, $positionMatch) || $positionMatch[1] === '') {
            return null;
        }

        $name = preg_match(self::NAME_PATTERN, $body, $nameMatch)
            ? trim(html_entity_decode(strip_tags($nameMatch[1])))
            : '';

        return [
            'id' => $idMatch[1],
            'name' => $name,
            'party' => preg_match(self::PARTY_PATTERN, $body, $partyMatch) ? $partyMatch[1] : '',
            'district' => preg_match(self::DISTRICT_PATTERN, $body, $districtMatch) ? $districtMatch[1] : '',
            'position' => $positionMatch[1],
        ];
    }
}
