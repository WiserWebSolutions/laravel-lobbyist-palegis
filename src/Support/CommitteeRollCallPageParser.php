<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\Lobbyist\Data\VoteCast;

/**
 * Extracts one committee roll call -- its committee, date, bill, motion,
 * tallies, and every member's individual position -- from a chamber's
 * `/{house|senate}/committees/roll-call-votes/vote-list/vote-summary?sessYr=...&sessInd=...&committeecode=C&rollcallid=N`
 * page.
 *
 * Unlike a floor roll call ({@see RollCallPageParser}), this identifier is
 * scoped to one committee: the same `rollcallid` under the wrong
 * `committeecode` renders a page with no vote data at all (verified live --
 * it is not a 404, just an empty result), so
 * {@see CommitteeRollCallEnumerator} must know each committee's code before
 * it can walk that committee's roll calls (see
 * {@see CommitteeListPageParser}).
 *
 * Member rows here carry no party or district at all, unlike a floor roll
 * call's -- only a name, a bio link (the member id), and an optional "Chair"
 * badge alongside whichever party controls that half of the list (not
 * captured, since {@see VoteCast} has no
 * party field to put it on).
 *
 * Scraped rather than a stable, versioned contract -- like
 * {@see MembersPageParser}/{@see SessionDayPageParser}, a redesign of this
 * page breaks the sync silently (a parse that finds no members) rather than
 * failing loudly the way a malformed RSS response would.
 */
class CommitteeRollCallPageParser
{
    private const MEMBER_PATTERN = '/<li class="list-group-item[^"]*">(.*?)<\/li>/is';

    private const MEMBER_ID_PATTERN = '/\/bio\/([0-9]+)\//i';

    private const NAME_PATTERN = '/<a[^>]*>\s*(?:Rep\.|Sen\.)?\s*([^<]*?)\s*<\/a>/is';

    private const POSITION_PATTERN = '/badge text-bg-[a-z-]+[^"]*"\s+title="([^"]*)"/i';

    private const COMMITTEE_NAME_PATTERN = '/class=[\'"]committee\s*[\'"][^>]*>([^<]*)<\/a>/i';

    private const DATE_PATTERN = '/rollcalldate=([0-9]{4}-[0-9]{2}-[0-9]{2})/i';

    private const BILL_PATTERN = '/\/legislation\/bills\/([0-9]+)\/([a-z])([a-z])([0-9]+)[\'"]/i';

    private const MOTION_PATTERN = '/Type of Motion\s*<\/div>\s*(?:\r?\n\s*)*<div[^>]*>\s*([^<]*?)\s*<\/div>/is';

    private const TALLY_PATTERN = '/<th[^>]*>\s*(Yeas|Nays|No Votes)\s*<\/th>\s*<td[^>]*>\s*([0-9]+)\s*<\/td>/i';

    /**
     * @return array{committee: ?string, date: ?string, bill: ?array{year: string, body: string, type: string, number: string}, motion: ?string, tallies: array<string, int>, positions: list<array{id: string, name: string, position: string}>}
     *
     * @throws PalegisException When no member rows are found -- either a page
     *                          redesign, or (see class doc) a `rollcallid`/`committeecode` pair that
     *                          does not match, which the caller should treat the same as "not found".
     */
    public static function parse(string $html): array
    {
        if (! preg_match_all(self::MEMBER_PATTERN, $html, $matches, PREG_SET_ORDER) || $matches === []) {
            throw new PalegisException(
                'No members found on the committee roll call page. Either palegis.us redesigned it, or '
                .'this rollcallid does not belong to the requested committeecode -- '
                .'CommitteeRollCallPageParser expects a <li class="list-group-item ..."> per member.'
            );
        }

        return [
            'committee' => self::committeeName($html),
            'date' => preg_match(self::DATE_PATTERN, $html, $m) ? $m[1] : null,
            'bill' => self::bill($html),
            'motion' => self::motion($html),
            'tallies' => self::tallies($html),
            'positions' => array_values(array_filter(array_map(
                fn (array $match): ?array => self::toPosition($match[1]),
                $matches
            ))),
        ];
    }

    private static function committeeName(string $html): ?string
    {
        return preg_match(self::COMMITTEE_NAME_PATTERN, $html, $match)
            ? trim(html_entity_decode(strip_tags($match[1])))
            : null;
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

    private static function motion(string $html): ?string
    {
        return preg_match(self::MOTION_PATTERN, $html, $match) && trim($match[1]) !== ''
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
            $key = match ($match[1]) {
                'Yeas' => 'yea',
                'Nays' => 'nay',
                'No Votes' => 'no_vote',
                default => null,
            };

            if ($key !== null) {
                $tallies[$key] = (int) $match[2];
            }
        }

        return $tallies;
    }

    /**
     * @return array{id: string, name: string, position: string}|null
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
            'position' => $positionMatch[1],
        ];
    }
}
