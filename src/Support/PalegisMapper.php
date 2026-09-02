<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Data\BillText;
use WiserWebSolutions\Lobbyist\Data\CommitteeAssignment;
use WiserWebSolutions\Lobbyist\Data\CommitteeMeeting;
use WiserWebSolutions\Lobbyist\Data\BillTextCollection;
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
            'texts' => self::billTextHistory($record),
        ];

        if ($includeRaw) {
            $meta['raw'] = $record;
        }

        return new Bill(meta: $meta);
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
