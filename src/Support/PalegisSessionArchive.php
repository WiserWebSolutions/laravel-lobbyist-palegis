<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

use Generator;
use Illuminate\Support\LazyCollection;
use WiserWebSolutions\LaravelPalegis\LaravelPalegis;
use WiserWebSolutions\LaravelPalegis\PalegisDriver;
use WiserWebSolutions\Lobbyist\Contracts\DatasetArchive;
use WiserWebSolutions\Lobbyist\Data\Dataset;
use WiserWebSolutions\Lobbyist\Data\LegislatorCollection;
use WiserWebSolutions\Lobbyist\Support\ZipDatasetArchive;

/**
 * A {@see DatasetArchive} over one session's Bill History Data export, plus
 * its member roster.
 *
 * Unlike {@see ZipDatasetArchive}, this
 * is not backed by a single downloaded file: {@see bills()} streams the Bill
 * History export (already downloaded and cached per-bill by
 * {@see LaravelPalegis::eachBillHistoryRecord()}), and {@see people()} wraps
 * a member roster fetched once up front (see
 * {@see PalegisDriver::dataset()}) rather
 * than a second stream. {@see path()} therefore has no real file to point
 * at; see {@see DatasetArchive::path()}.
 *
 * {@see votes()} is empty for now -- palegis.us publishes roll-call votes
 * only as scraped HTML pages (one page per roll call, enumerated by number),
 * which is a larger, separate piece of work than the bulk bill import this
 * exists to serve. A driver that cannot supply this yet still satisfies the
 * contract by returning no votes rather than fabricating any.
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
        return LazyCollection::empty();
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
