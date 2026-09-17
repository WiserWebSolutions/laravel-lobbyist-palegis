<?php

namespace WiserWebSolutions\LaravelPalegis;

use Illuminate\Support\Facades\Cache;
use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\Support\BillHistoryCacheMiss;
use WiserWebSolutions\LaravelPalegis\Support\PalegisMapper;
use WiserWebSolutions\LaravelPalegis\Support\PalegisSessionArchive;
use WiserWebSolutions\LaravelPalegis\Support\RollCallEnumerator;
use WiserWebSolutions\LaravelPalegis\Support\SessionDayPageParser;
use WiserWebSolutions\Lobbyist\Contracts\DatasetArchive;
use WiserWebSolutions\Lobbyist\Contracts\Providers\BillChangeProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\BillLookup;
use WiserWebSolutions\Lobbyist\Contracts\Providers\BillProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\BillTextHistoryLookup;
use WiserWebSolutions\Lobbyist\Contracts\Providers\BillTextLookup;
use WiserWebSolutions\Lobbyist\Contracts\Providers\ChamberSessionScheduleProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\CommitteeAssignmentProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\CommitteeScheduleProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\DatasetLookup;
use WiserWebSolutions\Lobbyist\Contracts\Providers\DatasetProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\LegislatorProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\SessionProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\VoteProvider;
use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Data\BillCollection;
use WiserWebSolutions\Lobbyist\Data\BillText;
use WiserWebSolutions\Lobbyist\Data\BillTextCollection;
use WiserWebSolutions\Lobbyist\Data\ChamberSessionDayCollection;
use WiserWebSolutions\Lobbyist\Data\CommitteeAssignmentCollection;
use WiserWebSolutions\Lobbyist\Data\CommitteeMeetingCollection;
use WiserWebSolutions\Lobbyist\Data\Dataset;
use WiserWebSolutions\Lobbyist\Data\DatasetCollection;
use WiserWebSolutions\Lobbyist\Data\Legislator;
use WiserWebSolutions\Lobbyist\Data\LegislatorCollection;
use WiserWebSolutions\Lobbyist\Data\SessionCollection;
use WiserWebSolutions\Lobbyist\Data\VoteCollection;
use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Enums\StateEnum;
use WiserWebSolutions\Lobbyist\Exceptions\UnsupportedOperationException;
use WiserWebSolutions\Lobbyist\Support\AbstractDriver;

/**
 * Pennsylvania driver backed by palegis.us.
 *
 * Roll-call votes and members come from the (browse-only) RSS feeds, while
 * bills come from the Bill History Data export — a bulk download of every bill
 * and resolution in the session, which also backs bill lookups by number/id.
 * Votes and members cannot be resolved by arbitrary identifier, so this driver
 * implements VoteProvider/LegislatorProvider but not their *Lookup
 * interfaces; calling vote()/representative() throws
 * {@see UnsupportedOperationException}
 * via {@see AbstractDriver}. {@see legislators()} merges the House and Senate
 * members feeds; {@see representatives()}/{@see senators()} are that same list
 * filtered by chamber. Feeds without a core-DTO mapping (bill floor
 * calendars, journals, amendments, memos, …) are available on the
 * underlying {@see LaravelPalegis} client.
 *
 * {@see legislatorsForSession()} (and its chamber-filtered
 * {@see representativesForSession()}/{@see senatorsForSession()}) reach past
 * General Assemblies the Members feeds cannot: those feeds only ever reflect
 * who is sitting today, so a past session's roster is scraped from the
 * `/members?SessYr=` pages instead (see {@see Support\MembersPageParser}).
 *
 * {@see chamberSessionDays()} is the one thing here not read from an RSS feed
 * at all — palegis.us publishes no feed of when a chamber itself convenes,
 * only an HTML page listing every session day of the current session (see
 * {@see SessionDayPageParser}).
 *
 * Bill text history is each bill's printer-number history from the Bill
 * History export — every printer's number is one revision of the bill's text.
 * {@see PalegisMapper::billTextHistory()} maps them cheaply (each entry's
 * `url`/`toHTML()` point at the HTML rendering, `toPDF()` at the PDF the
 * export links directly, with {@see BillText::$content} left null) and is
 * embedded on every mapped {@see Bill} via {@see Bill::texts()}/{@see Bill::text()}
 * for free — no extra request beyond the one that already fetched the bill.
 * {@see billTextHistory()}/{@see self::billText()} expose the same data at the
 * driver level for lookups by identifier alone; {@see self::billText()} additionally
 * fetches the latest version's HTML and strips it down to plain text for
 * {@see BillText::$content} (`Bill::text()`'s own `toString()` throws instead,
 * since it never performs I/O on its own).
 *
 * {@see datasets()}/{@see self::dataset()} expose the Bill History Data export as a
 * {@see DatasetArchive}, backed by
 * {@see PalegisSessionArchive} — the same export {@see bills()} and
 * {@see self::bill()} already read, just streamed instead of listed/looked-up one
 * at a time, and paired with the member roster for sponsor identity. Its
 * archive's {@see DatasetArchive::votes()} walks both chambers' floor roll
 * calls by number via {@see RollCallEnumerator} -- the Bill History export
 * itself carries no votes at all, so this is a second, independent source
 * read alongside it, each completed roll call cached forever once read
 * (see {@see RollCallEnumerator}'s own class doc). {@see billChanges()}
 * reuses {@see bills()} verbatim — the summary listing already carries each
 * bill's {@see Bill::$changeHash} (its `lastUpdate` from the export), and
 * there is no cheaper listing this source offers.
 */
class PalegisDriver extends AbstractDriver implements BillChangeProvider, BillLookup, BillProvider, BillTextHistoryLookup, BillTextLookup, ChamberSessionScheduleProvider, CommitteeAssignmentProvider, CommitteeScheduleProvider, DatasetLookup, DatasetProvider, LegislatorProvider, SessionProvider, VoteProvider
{
    /** @var array<string, Chamber> */
    private const CHAMBERS = [
        'house' => Chamber::House,
        'senate' => Chamber::Senate,
    ];

    public function __construct(private readonly LaravelPalegis $client) {}

    public function sessions(): SessionCollection
    {
        return new SessionCollection([
            PalegisMapper::currentSession(),
        ]);
    }

    public function bills(): BillCollection
    {
        try {
            $bills = [];

            foreach ($this->client->eachBillHistoryRecord() as $record) {
                $bills[] = PalegisMapper::billSummaryFromHistory($record);
            }
        } catch (BillHistoryCacheMiss) {
            // A per-bill cache entry expired mid-stream; discard whatever
            // was built above and do one consistent resync + rebuild.
            $bills = array_map(
                fn (array $record) => PalegisMapper::billSummaryFromHistory($record),
                $this->client->syncBillHistory()['bills'] ?? []
            );
        }

        return new BillCollection($bills);
    }

    public function bill(string|int $identifier): Bill
    {
        return PalegisMapper::billFromHistory($this->findBillRecord($identifier));
    }

    /**
     * The cheapest listing this source offers -- there is no separate
     * "changed since" endpoint, so this is {@see bills()} verbatim. Every
     * entry already carries {@see Bill::$changeHash} (the export's per-bill
     * `lastUpdate`), which is what makes it useful for change detection at
     * all.
     */
    public function billChanges(): BillCollection
    {
        return $this->bills();
    }

    public function billTextHistory(string|int $identifier): BillTextCollection
    {
        return PalegisMapper::billTextHistory($this->findBillRecord($identifier));
    }

    public function billText(string|int $identifier): BillText
    {
        $latest = $this->billTextHistory($identifier)->latest();

        if ($latest === null) {
            throw new PalegisException("Bill [{$identifier}] has no text versions in the PA bill history.");
        }

        $html = $this->client->fetchBillText($latest->url);
        $content = trim(html_entity_decode(strip_tags($html)));

        return new BillText(meta: [...$latest->meta, 'content' => $content]);
    }

    public function votes(): VoteCollection
    {
        $votes = [];

        foreach ($this->itemsFor('votes') as [$item, $chamber]) {
            $votes[] = PalegisMapper::vote($item, $chamber);
        }

        return new VoteCollection($votes);
    }

    /**
     * Committee rosters and leadership for both chambers.
     *
     * The one thing this source has that the bill aggregators do not: an
     * actual roster, with who chairs each committee.
     */
    public function committeeAssignments(): CommitteeAssignmentCollection
    {
        $assignments = new CommitteeAssignmentCollection;

        foreach (self::CHAMBERS as $chamber => $enum) {
            $feed = $chamber === 'house'
                ? $this->client->getHouseCommitteeAssignments()
                : $this->client->getSenateCommitteeAssignments();

            foreach ($feed['items'] ?? [] as $item) {
                foreach (PalegisMapper::committeeAssignments($item, $enum) as $assignment) {
                    $assignments->push($assignment);
                }
            }
        }

        return $assignments;
    }

    /**
     * Scheduled public committee meetings for both chambers.
     */
    public function committeeMeetings(): CommitteeMeetingCollection
    {
        $meetings = new CommitteeMeetingCollection;

        foreach (self::CHAMBERS as $chamber => $enum) {
            $feed = $chamber === 'house'
                ? $this->client->getHouseCommitteeSchedule()
                : $this->client->getSenateCommitteeSchedule();

            foreach ($feed['items'] ?? [] as $item) {
                $meetings->push(PalegisMapper::committeeMeeting($item, $enum));
            }
        }

        return $meetings;
    }

    /**
     * The session-day calendar for both chambers -- when the House and
     * Senate themselves convene, not when a committee meets ({@see committeeMeetings()})
     * or which bills are next on a chamber's floor calendar (unmapped; see
     * the class docblock).
     */
    public function chamberSessionDays(): ChamberSessionDayCollection
    {
        $days = new ChamberSessionDayCollection;

        foreach (self::CHAMBERS as $chamber => $enum) {
            $rows = $chamber === 'house'
                ? $this->client->getHouseSessionDays()
                : $this->client->getSenateSessionDays();

            foreach ($rows as $row) {
                $days->push(PalegisMapper::chamberSessionDay($row, $enum));
            }
        }

        return $days;
    }

    public function legislators(): LegislatorCollection
    {
        $legislators = [];

        foreach ($this->itemsFor('members') as [$item, $chamber]) {
            $legislators[] = PalegisMapper::legislator($item, $chamber);
        }

        return new LegislatorCollection($legislators);
    }

    public function representatives(): LegislatorCollection
    {
        return $this->legislators()->byChamber(Chamber::House);
    }

    public function senators(): LegislatorCollection
    {
        return $this->legislators()->byChamber(Chamber::Senate);
    }

    /**
     * Both chambers' member rosters for a given session, scraped from the
     * `/members` pages rather than the current-roster-only Members RSS feed
     * {@see legislators()} uses -- the one way to reach a past General
     * Assembly's roster. Not part of {@see LegislatorProvider}: that contract
     * has no notion of "a session other than the current one".
     *
     * @param  string|null  $session  palegis session id (e.g. "2007_0" for the
     *                                2007-2008 session); null gets the current
     *                                roster.
     */
    public function legislatorsForSession(?string $session = null): LegislatorCollection
    {
        $legislators = [];

        foreach (self::CHAMBERS as $chamber => $enum) {
            $rows = $chamber === 'house'
                ? $this->client->getHouseMembersForSession($session)
                : $this->client->getSenateMembersForSession($session);

            foreach ($rows as $row) {
                $legislators[] = PalegisMapper::legislatorFromMembersPage($row, $enum);
            }
        }

        return new LegislatorCollection($legislators);
    }

    /** @param  string|null  $session  palegis session id (e.g. "2007_0"); null gets the current roster. */
    public function representativesForSession(?string $session = null): LegislatorCollection
    {
        return $this->legislatorsForSession($session)->byChamber(Chamber::House);
    }

    /** @param  string|null  $session  palegis session id (e.g. "2007_0"); null gets the current roster. */
    public function senatorsForSession(?string $session = null): LegislatorCollection
    {
        return $this->legislatorsForSession($session)->byChamber(Chamber::Senate);
    }

    /**
     * Every Bill History Data session published on palegis.us, back to 1969.
     */
    public function datasets(): DatasetCollection
    {
        $sessions = $this->client->getBillHistorySessions();

        return new DatasetCollection(array_map(
            fn (array $row): Dataset => new Dataset(meta: [
                'session_id' => $row['session'],
                'session_name' => $row['name'],
                'state' => StateEnum::PA,
                // The export's own rebuild timestamp, not any one bill's --
                // see PalegisMapper::billFromHistory()'s per-bill change_hash
                // for that.
                'hash' => $row['last_updated'],
                'year_start' => $row['year_start'],
                'year_end' => $row['year_end'],
            ]),
            $sessions
        ));
    }

    /**
     * Open one session's Bill History Data export as a
     * {@see DatasetArchive}, paired
     * with that session's member roster for sponsor identity (see
     * {@see PalegisMapper::rosterKey()}).
     */
    public function dataset(Dataset|int|string $session): DatasetArchive
    {
        $dataset = $session instanceof Dataset ? $session : $this->datasets()->forSession($session);

        if ($dataset === null) {
            throw new PalegisException("No published Bill History Data session found for [{$session}].");
        }

        $sessionId = (string) $dataset->sessionId;
        $roster = $this->legislatorsForSession($sessionId);

        return new PalegisSessionArchive(
            dataset: $dataset,
            client: $this->client,
            session: $sessionId,
            roster: $roster,
            rosterIndex: $this->rosterIndex($roster),
            rollCalls: $this->rollCallEnumerator(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function rosterIndex(LegislatorCollection $roster): array
    {
        $index = [];

        foreach ($roster as $legislator) {
            /** @var Legislator $legislator */
            $index[PalegisMapper::rosterKey($legislator->chamber, $legislator->district)] = (string) $legislator->id;
        }

        return $index;
    }

    private function rollCallEnumerator(): RollCallEnumerator
    {
        return new RollCallEnumerator(
            cache: Cache::store(config('palegis.cache.store')),
            maxConsecutiveMisses: (int) config('palegis.roll_calls.max_consecutive_misses', 5),
            request: (array) config('palegis.request', []),
            hardCeiling: (int) config('palegis.roll_calls.hard_ceiling', 5000),
        );
    }

    private function findBillRecord(string|int $identifier): array
    {
        $record = $this->client->findBill(null, (string) $identifier);

        if ($record === null) {
            throw new PalegisException("Bill [{$identifier}] was not found in the PA bill history.");
        }

        return $record;
    }

    /**
     * Collect items across both chambers for a feed type, skipping chambers
     * that do not publish that feed. Each element is [item, Chamber].
     *
     * @return list<array{0: array, 1: Chamber}>
     */
    private function itemsFor(string $feedType): array
    {
        $results = [];

        foreach (self::CHAMBERS as $chamber => $enum) {
            if (! in_array($feedType, $this->client->getAvailableFeeds($chamber), true)) {
                continue;
            }

            $feed = $this->client->fetchRssFeed($chamber, $feedType);

            foreach ($feed['items'] ?? [] as $item) {
                $results[] = [$item, $enum];
            }
        }

        return $results;
    }
}
