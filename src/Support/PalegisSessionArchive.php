<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use Generator;
use Illuminate\Support\LazyCollection;
use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\LaravelPalegis;
use WiserWebSolutions\LaravelPalegis\PalegisDriver;
use WiserWebSolutions\Lobbyist\Contracts\DatasetArchive;
use WiserWebSolutions\Lobbyist\Data\Dataset;
use WiserWebSolutions\Lobbyist\Data\LegislatorCollection;
use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Support\ZipDatasetArchive;

/**
 * A {@see DatasetArchive} over one session's Bill History Data export, plus
 * its member roster and floor/committee roll calls.
 *
 * Unlike {@see ZipDatasetArchive}, this
 * is not backed by a single downloaded file: {@see bills()} streams the Bill
 * History export (already downloaded and cached per-bill by
 * {@see LaravelPalegis::eachBillHistoryRecord()}), {@see people()} wraps
 * a member roster fetched once up front (see
 * {@see PalegisDriver::dataset()}), and {@see votes()} walks both chambers'
 * floor roll calls by number via {@see RollCallEnumerator}, then every
 * committee's own roll calls via {@see CommitteeRollCallEnumerator} -- four
 * independent reads, not one file split four ways. {@see path()} therefore
 * has no real file to point at; see {@see DatasetArchive::path()}.
 */
class PalegisSessionArchive implements DatasetArchive
{
    /**
     * @param  array<string, string>  $rosterIndex  See {@see PalegisMapper::rosterKey()}.
     */
    public function __construct(
        private readonly Dataset $dataset,
        private readonly LaravelPalegis $client,
        private readonly string $session,
        private readonly LegislatorCollection $roster,
        private readonly array $rosterIndex,
        private readonly RollCallEnumerator $rollCalls,
        private readonly CommitteeRollCallEnumerator $committeeRollCalls,
    ) {}

    public function dataset(): Dataset
    {
        return $this->dataset;
    }

    public function path(): string
    {
        return "palegis://bill-history/{$this->session}";
    }

    public function bills(): LazyCollection
    {
        return LazyCollection::make(function (): Generator {
            foreach ($this->client->eachBillHistoryRecord($this->session) as $record) {
                yield PalegisMapper::billFromHistory($record, $this->rosterIndex);
            }
        });
    }

    public function votes(): LazyCollection
    {
        return LazyCollection::make(function (): Generator {
            foreach (['house' => Chamber::House, 'senate' => Chamber::Senate] as $slug => $chamber) {
                foreach ($this->rollCalls->walk($slug, $this->session) as $record) {
                    yield PalegisMapper::voteFromRollCall($record, $record['rc_num'], $chamber);
                }

                try {
                    $committees = $slug === 'house'
                        ? $this->client->getHouseCommitteeList()
                        : $this->client->getSenateCommitteeList();
                } catch (PalegisException) {
                    // A committee list this can't fetch or parse (a page
                    // redesign, a request failure) should not sink the whole
                    // votes() stream -- floor votes for this chamber, and
                    // both parts for the other chamber, are still good.
                    continue;
                }

                foreach ($this->committeeRollCalls->walkAll($slug, $this->session, $committees) as $record) {
                    yield PalegisMapper::voteFromCommitteeRollCall($record, $this->session, $chamber);
                }
            }
        });
    }

    public function people(): LazyCollection
    {
        return LazyCollection::make(fn (): Generator => yield from $this->roster);
    }

    /**
     * @return array{bills: int, votes: int, people: int}
     */
    public function counts(): array
    {
        // getBillHistory() reads the export's `totalDocuments` header as
        // part of the same download/parse {@see bills()} would otherwise
        // trigger on its own first read, and warms the per-bill cache in the
        // process -- so calling this first (as DatasetImporter does, to
        // report archive size before importing) costs nothing extra: bills()
        // then reads the now-warm cache instead of downloading twice.
        //
        // 'votes' is always 0: unlike bills, there is no cheap header to
        // read it from -- the only way to count roll calls is to walk them,
        // which is exactly the expensive work this method exists to avoid
        // paying twice. The progress line built from this undercounts votes
        // rather than walking the chamber twice to report a true figure.
        $total = $this->client->getBillHistory($this->session)['total'] ?? 0;

        return ['bills' => $total, 'votes' => 0, 'people' => $this->roster->count()];
    }

    public function delete(): void
    {
        // Nothing owned exclusively by this archive: the Bill History cache
        // is keyed per-bill and shared across imports (see
        // {@see \WiserWebSolutions\LaravelPalegis\Support\BillHistoryCache}),
        // and BillHistoryFetcher already cleans up its own temp files as
        // soon as each download/extraction finishes.
    }
}
