<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use WiserWebSolutions\LaravelPalegis\LaravelPalegis;
use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Data\BillText;
use WiserWebSolutions\Lobbyist\Data\BillTextCollection;
use WiserWebSolutions\Lobbyist\Data\ChamberSessionDay;
use WiserWebSolutions\Lobbyist\Data\CommitteeAssignment;
use WiserWebSolutions\Lobbyist\Data\CommitteeMeeting;
use WiserWebSolutions\Lobbyist\Data\Legislator;
use WiserWebSolutions\Lobbyist\Data\Session;
use WiserWebSolutions\Lobbyist\Data\Vote;
use WiserWebSolutions\Lobbyist\Data\VoteCast;
use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Enums\SponsorType;
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
     * (sponsors, full action history, full printer-number history, plus a
     * derived `referrals` list -- see {@see ActionStatusMapper::referrals()})
     * since only one record is materialized at a time.
     *
     * @param  array<string, string>  $rosterIndex  Sponsor identity join: maps
     *                                              {@see rosterKey()} to a member id, so a sponsor entry (which carries
     *                                              only chamber/party/district, no id) can be resolved to the same
     *                                              legislator {@see \WiserWebSolutions\LaravelPalegis\PalegisDriver::legislators()}
     *                                              imports. Omit it (or pass an empty array) when the caller does not
     *                                              need sponsors resolved to an identity -- every sponsor then maps
     *                                              with an empty id, which downstream sponsor-sync code already
     *                                              treats as "no matching legislator" and skips.
     */
    public static function billFromHistory(array $record, array $rosterIndex = []): Bill
    {
        return self::billDto($record, includeRaw: true, rosterIndex: $rosterIndex);
    }

    /**
     * Map a Bill History Data record to a lightweight-summary Bill, for
     * listing every bill in a session at once. Omits the raw record
     * (complete action history, complete printer-number history)
     * — a session can hold thousands of bills, and retaining full detail on
     * every one of them when only the summary fields are needed is the
     * majority of the memory cost of listing them all.
     *
     * @param  array<string, string>  $rosterIndex  See {@see billFromHistory()}.
     */
    public static function billSummaryFromHistory(array $record, array $rosterIndex = []): Bill
    {
        return self::billDto($record, includeRaw: false, rosterIndex: $rosterIndex);
    }

    private static function billDto(array $record, bool $includeRaw, array $rosterIndex = []): Bill
    {
        $lastAction = ! empty($record['actions']) ? end($record['actions']) : null;
        $lastPrinters = ! empty($record['printers_numbers']) ? end($record['printers_numbers']) : null;

        ['status' => $status, 'status_date' => $statusDate] = ActionStatusMapper::status($record['actions'] ?? []);

        $memo = trim((string) ($record['cosponsorship_memo']['text'] ?? ''));

        $meta = [
            'id' => $record['id'] ?? '',
            'number' => $record['designator'] ?? '',
            'title' => $record['short_title'] ?? '',
            'description' => $memo !== '' ? $memo : ($record['short_title'] ?? ''),
            'state' => StateEnum::PA,
            'chamber' => Chamber::fromString($record['body'] ?? null),
            'status' => $status,
            'status_date' => $statusDate,
            'last_action' => $lastAction['full_action'] ?? '',
            'last_action_date' => $lastAction['date'] ?? null,
            'url' => $lastPrinters['pdf_url'] ?? '',
            // The export's own per-bill revision marker -- see
            // {@see \WiserWebSolutions\LaravelPalegis\Support\DataPageParser}'s
            // docblock for how this differs from the whole archive's own hash.
            'change_hash' => ($record['last_update'] ?? '') !== '' ? $record['last_update'] : null,
            'texts' => self::billTextHistory($record),
            'sponsors' => self::sponsors($record['sponsors'] ?? [], $rosterIndex),
        ];

        if ($includeRaw) {
            $meta['raw'] = [...$record, 'referrals' => ActionStatusMapper::referrals($record['actions'] ?? [])];
        }

        return new Bill(meta: $meta);
    }

    /**
     * Map a Bill History sponsor row (name, party, body, district,
     * sequence -- no member id) to a {@see Legislator}, resolved to an
     * identity via {@see $rosterIndex} keyed by {@see rosterKey()}. Sequence
     * "01" is always the prime sponsor; every other sequence is a co-sponsor
     * -- the export lists them in that order, and there is no separate joint-
     * sponsor concept in these feeds.
     *
     * @param  array<int, array{name?: string, party?: string, body?: string, district?: string, sequence?: string}>  $sponsors
     * @param  array<string, string>  $rosterIndex
     * @return list<Legislator>
     */
    private static function sponsors(array $sponsors, array $rosterIndex): array
    {
        return array_values(array_map(
            fn (array $sponsor): Legislator => new Legislator(meta: [
                'id' => $rosterIndex[self::rosterKey($sponsor['body'] ?? null, $sponsor['district'] ?? null)] ?? '',
                'name' => trim((string) ($sponsor['name'] ?? '')),
                'chamber' => Chamber::fromString($sponsor['body'] ?? null),
                'party' => ($sponsor['party'] ?? '') !== '' ? $sponsor['party'] : null,
                'district' => ($sponsor['district'] ?? '') !== '' ? $sponsor['district'] : null,
                'sponsor_type' => ($sponsor['sequence'] ?? '') === '01' ? SponsorType::Primary : SponsorType::CoSponsor,
                'sponsor_order' => ($sponsor['sequence'] ?? '') !== '' ? (int) $sponsor['sequence'] : null,
            ]),
            $sponsors
        ));
    }

    /**
     * A chamber+district key shared between a sponsor row (chamber as
     * "H"/"S", district as a string, possibly zero-padded) and a roster
     * built from {@see Legislator} DTOs (chamber as a {@see Chamber} enum),
     * so both sides normalize to the same key regardless of which shape they
     * started from.
     */
    public static function rosterKey(Chamber|string|null $chamber, ?string $district): string
    {
        $chamberValue = $chamber instanceof Chamber ? $chamber->value : Chamber::fromString($chamber)?->value;
        $number = (int) preg_replace('/[^0-9]/', '', (string) $district);

        return ($chamberValue ?? '').'|'.$number;
    }

    /**
     * Map a Bill History record's printer-number history to a version-by-version
     * {@see BillTextCollection}. Each printer's number is one text revision,
     * offered in two formats: its primary `url` is the HTML rendering (used
     * for {@see BillText::toString()}'s stripped-text fetch), and `links`
     * carries the PDF the export gives us directly. No fetched bytes here —
     * {@see PalegisDriver::billText()} fetches the latest version's content
     * lazily. Each entry's date is recovered from the action that first
     * reported that printer's number, if any.
     */
    public static function billTextHistory(array $record): BillTextCollection
    {
        $billId = $record['id'] ?? '';

        return new BillTextCollection(
            array_map(
                fn (array $printer) => new BillText(meta: [
                    'id' => $printer['number'] ?? '',
                    'bill_id' => $billId,
                    'type' => "Printer's Number {$printer['number']}",
                    'mime' => 'text/html',
                    'date' => self::printersNumberDate($record, $printer),
                    'url' => self::htmUrl($printer['pdf_url'] ?? ''),
                    'links' => ($printer['pdf_url'] ?? '') !== ''
                        ? ['application/pdf' => $printer['pdf_url']]
                        : [],
                    'raw' => $printer,
                ]),
                $record['printers_numbers'] ?? []
            )
        );
    }

    /**
     * palegis.us publishes each printer's number as both a PDF and an HTML
     * page at the same path with only the format segment differing
     * (".../text/PDF/…" vs ".../text/HTM/…"). The Bill History export only
     * gives us the PDF link, so derive the HTML one from it rather than
     * rebuilding the path from its parts.
     */
    private static function htmUrl(string $pdfUrl): string
    {
        return str_replace('/text/PDF/', '/text/HTM/', $pdfUrl);
    }

    /**
     * The date of the action that first reported a given printer's number,
     * cross-referenced via each action's `printers_number` field.
     */
    private static function printersNumberDate(array $record, array $printer): ?string
    {
        foreach ($record['actions'] ?? [] as $action) {
            if (($action['printers_number'] ?? null) === ($printer['number'] ?? null)) {
                return $action['date'] ?? null;
            }
        }

        return null;
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

    /**
     * Map one {@see RollCallEnumerator} record (a floor roll call, including
     * every member's individual position) to a {@see Vote}.
     *
     * `id` is prefixed with the chamber, since House and Senate roll call
     * numbers are independent sequences that can collide (both can have an
     * `rcNum=1350`, say). `bill_id` is only ever set when the roll call
     * carries a bill link -- {@see RollCallPageParser} finds none on a
     * procedural roll call (a Master Roll Call, a quorum call), and is
     * rebuilt from the record's own year/session/body/type/number into the
     * exact format {@see billFromHistory()} uses for `Bill::$id`
     * ("20250HB0017"), not the bare designator ("HB17") -- that format is
     * what the app's own bill lookup keys on.
     */
    public static function voteFromRollCall(array $record, int $rcNum, Chamber $chamber): Vote
    {
        $tallies = $record['tallies'] ?? [];

        return new Vote(meta: [
            'id' => $chamber->value.':'.$rcNum,
            'bill_id' => self::billIdFromRollCall($record),
            'chamber' => $chamber,
            'date' => $record['date'] ?? null,
            'description' => $record['action'] ?? '',
            'yea' => $tallies['yea'] ?? null,
            'nay' => $tallies['nay'] ?? null,
            'nv' => $tallies['no_vote'] ?? null,
            'absent' => $tallies['leave'] ?? null,
            'passed' => isset($tallies['yea'], $tallies['nay']) ? $tallies['yea'] > $tallies['nay'] : null,
            'url' => sprintf(
                'https://www.palegis.us/%s/roll-calls/summary?sessYr=%s&sessInd=%s&rcNum=%d',
                $chamber === Chamber::Senate ? 'senate' : 'house',
                $record['session_year'] ?? '',
                $record['session_index'] ?? '0',
                $rcNum,
            ),
            'positions' => array_map(
                fn (array $position): VoteCast => new VoteCast(meta: [
                    'legislator_id' => $position['id'],
                    'position' => $position['position'],
                ]),
                $record['positions'] ?? []
            ),
            'raw' => $record,
        ]);
    }

    private static function billIdFromRollCall(array $record): ?string
    {
        $bill = $record['bill'] ?? null;

        if ($bill === null) {
            return null;
        }

        return sprintf(
            '%s%s%s%s%04d',
            $bill['year'] ?? '',
            $record['session_index'] ?? '0',
            $bill['body'] ?? '',
            $bill['type'] ?? '',
            (int) ($bill['number'] ?? 0),
        );
    }

    public static function legislator(array $item, Chamber $chamber): Legislator
    {
        return new Legislator(meta: [
            // The member id from the bio link, which is the only stable
            // identity in the feed -- the guid embeds a timestamp and changes
            // every time the member updates their page.
            'id' => self::memberId($item['link'] ?? '') ?? ($item['guid'] ?? ''),
            'name' => $item['title'] ?? '',
            'chamber' => $chamber,
            'role' => $item['description'] ?? null,
            'party' => self::extension($item, 'Party'),
            'district' => self::extension($item, 'District'),
            'county' => self::extension($item, 'County'),
            'image_url' => self::extension($item, 'ImageSrc'),
            'capitol_phone' => self::extension($item, 'CapitolAddress_Phone'),
            'district_phone' => self::extension($item, 'District_1_Phone'),
            'active' => self::extension($item, 'Vacant') === 'true' ? false : true,
            'state' => StateEnum::PA,
            'url' => $item['link'] ?? '',
            'raw' => $item,
        ]);
    }

    /**
     * Map a member roster row (see {@see LaravelPalegis::getHouseMembersForSession()}/
     * {@see LaravelPalegis::getSenateMembersForSession()}, scraped by
     * {@see MembersPageParser}) to a Legislator.
     *
     * Distinct from {@see self::legislator()} (the RSS mapping): this source can
     * reach past sessions the current-roster-only Members feed cannot, but in
     * exchange doesn't carry every field the feed does (no phone numbers, no
     * explicit active/vacant flag) -- every row here was, by definition, a
     * seated member of the session requested.
     *
     * @param  array{id: string, name: string, party: string, district: string, county: string, leadership: string, image_url: string, url: string}  $row
     */
    public static function legislatorFromMembersPage(array $row, Chamber $chamber): Legislator
    {
        return new Legislator(meta: [
            'id' => $row['id'] ?? '',
            'name' => $row['name'] ?? '',
            'chamber' => $chamber,
            'role' => ($row['leadership'] ?? '') !== '' ? $row['leadership'] : null,
            'party' => $row['party'] ?? null,
            'district' => $row['district'] ?? null,
            'county' => $row['county'] ?? null,
            'image_url' => $row['image_url'] ?? null,
            'active' => true,
            'state' => StateEnum::PA,
            'url' => $row['url'] ?? '',
            'raw' => $row,
        ]);
    }

    /**
     * Every committee seat one member holds, from the assignments feed.
     *
     * The feed is member-major: one item per legislator, listing their
     * committees, with leadership in a `position` attribute. So a single item
     * yields several assignments.
     *
     * @param  array<string, mixed>  $item
     * @return array<int, CommitteeAssignment>
     */
    public static function committeeAssignments(array $item, Chamber $chamber): array
    {
        // "Abney, Aerion (D) District 19" -- name, party and district in one
        // string, and the party is nowhere else in this feed.
        preg_match('/^(?<name>.*?)(?:\s*\((?<party>[A-Z])\))?(?:\s*District\s*(?<district>[0-9]+))?$/', trim((string) ($item['title'] ?? '')), $matches);

        $name = trim($matches['name'] ?? '');
        $party = $matches['party'] ?? null;
        $district = $matches['district'] ?? self::extension($item, 'District');
        $legislatorId = self::memberId($item['link'] ?? '');

        $assignments = [];

        foreach (['Committee' => null, 'Subcommittee' => 'committee'] as $element => $parentAttribute) {
            foreach (self::extensionEntries($item, $element) as $entry) {
                if ($entry['value'] === '') {
                    continue;
                }

                $assignments[] = new CommitteeAssignment(meta: [
                    'committee' => $entry['value'],
                    'chamber' => $chamber,
                    'legislator_id' => $legislatorId,
                    'legislator_name' => $name,
                    'district' => $district,
                    'party' => $party,
                    'position' => $entry['attributes']['position'] ?? null,
                    'parent_committee' => $parentAttribute === null
                        ? null
                        : ($entry['attributes'][$parentAttribute] ?? null),
                    'raw' => $entry,
                ]);
            }
        }

        return $assignments;
    }

    /**
     * One scheduled committee meeting.
     *
     * @param  array<string, mixed>  $item
     */
    public static function committeeMeeting(array $item, Chamber $chamber): CommitteeMeeting
    {
        $committee = self::extension($item, 'Committee') ?? '';

        return new CommitteeMeeting(meta: [
            // The feed prefixes the chamber onto the committee name ("HOUSE
            // EDUCATION") and shouts it. Stripped and title-cased, because a
            // caller matching against its own committee list should not have to
            // undo the feed's formatting.
            'committee' => self::normaliseCommitteeName($committee, $chamber),
            'chamber' => $chamber,
            'date' => self::extension($item, 'MeetingDate'),
            'time' => self::extension($item, 'MeetingTime'),
            'location' => self::extension($item, 'Location'),
            'description' => $item['description'] ?? null,
            // The guid carries date, time and committee, which is exactly the
            // identity of the event.
            'identifier' => (string) ($item['guid'] ?? ''),
            'url' => $item['link'] ?? '',
            'bills' => self::billNumbersFrom(self::extension($item, 'Bills') ?? ''),
            'raw' => $item,
        ]);
    }

    /**
     * One session day, from a row {@see LaravelPalegis::getHouseSessionDays()}/
     * {@see LaravelPalegis::getSenateSessionDays()} scraped off the chamber's
     * own `/session?days` page.
     *
     * @param  array{date: string, voting_day: bool, url: string}  $row
     */
    public static function chamberSessionDay(array $row, Chamber $chamber): ChamberSessionDay
    {
        return new ChamberSessionDay(meta: [
            'chamber' => $chamber,
            'date' => $row['date'] ?? null,
            'voting_day' => $row['voting_day'] ?? true,
            // The date alone identifies the day within a chamber; there is
            // only ever one session day per chamber per calendar date.
            'identifier' => $chamber->value.':'.($row['date'] ?? ''),
            'url' => $row['url'] ?? '',
        ]);
    }

    /**
     * Strip the chamber prefix the schedule feed adds, and drop the shouting.
     */
    private static function normaliseCommitteeName(string $committee, Chamber $chamber): string
    {
        $committee = trim(preg_replace('/^(house|senate)\s+/i', '', trim($committee)) ?? '');

        // Only re-case names that arrived in full capitals; a feed that already
        // wrote "Game & Fisheries" should keep it.
        if ($committee !== '' && $committee === mb_strtoupper($committee)) {
            $committee = mb_convert_case(mb_strtolower($committee), MB_CASE_TITLE, 'UTF-8');
        }

        return $committee;
    }

    /**
     * @return array<int, string>
     */
    private static function billNumbersFrom(string $value): array
    {
        preg_match_all('/\b([HS][BR]\s*[0-9]+)\b/i', $value, $matches);

        return array_map(
            fn (string $bill): string => strtoupper((string) preg_replace('/\s+/', '', $bill)),
            $matches[1] ?? []
        );
    }

    /**
     * The member id out of a bio link.
     */
    private static function memberId(string $link): ?string
    {
        preg_match('/memberId=([0-9]+)/i', $link, $matches);

        return $matches[1] ?? null;
    }

    /**
     * The first value of a namespaced feed element.
     *
     * @param  array<string, mixed>  $item
     */
    private static function extension(array $item, string $name): ?string
    {
        $value = self::extensionEntries($item, $name)[0]['value'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Every value of a namespaced feed element, with its attributes.
     *
     * @param  array<string, mixed>  $item
     * @return array<int, array{value: string, attributes: array<string, string>}>
     */
    private static function extensionEntries(array $item, string $name): array
    {
        $entries = $item['extensions'][$name] ?? [];

        return is_array($entries) ? $entries : [];
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
