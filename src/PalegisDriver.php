<?php

namespace WiserWebSolutions\LaravelPalegis;

use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\Support\PalegisMapper;
use WiserWebSolutions\Lobbyist\Contracts\Providers\BillLookup;
use WiserWebSolutions\Lobbyist\Contracts\Providers\BillProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\RepresentativeProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\SessionProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\VoteProvider;
use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Data\BillCollection;
use WiserWebSolutions\Lobbyist\Data\LegislatorCollection;
use WiserWebSolutions\Lobbyist\Data\SessionCollection;
use WiserWebSolutions\Lobbyist\Data\VoteCollection;
use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Exceptions\UnsupportedOperationException;
use WiserWebSolutions\Lobbyist\Support\AbstractDriver;

/**
 * Pennsylvania driver backed by palegis.us.
 *
 * Roll-call votes and members come from the (browse-only) RSS feeds, while
 * bills come from the Bill History Data export — a bulk download of every bill
 * and resolution in the session, which also backs bill lookups by number/id.
 * Votes and members cannot be resolved by arbitrary identifier, so this driver
 * implements VoteProvider/RepresentativeProvider but not their *Lookup
 * interfaces; calling vote()/representative() throws
 * {@see UnsupportedOperationException}
 * via {@see AbstractDriver}. Feeds without a core-DTO mapping (calendars,
 * journals, amendments, memos, …) are available on the underlying
 * {@see LaravelPalegis} client.
 */
class PalegisDriver extends AbstractDriver implements BillLookup, BillProvider, RepresentativeProvider, SessionProvider, VoteProvider
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
        $bills = array_map(
            fn (array $record) => PalegisMapper::billFromHistory($record),
            $this->client->getBillHistory()['bills'] ?? []
        );

        return new BillCollection($bills);
    }

    public function bill(string|int $identifier): Bill
    {
        $record = $this->client->findBill(null, (string) $identifier);

        if ($record === null) {
            throw new PalegisException("Bill [{$identifier}] was not found in the PA bill history.");
        }

        return PalegisMapper::billFromHistory($record);
    }

    public function votes(): VoteCollection
    {
        $votes = [];

        foreach ($this->itemsFor('votes') as [$item, $chamber]) {
            $votes[] = PalegisMapper::vote($item, $chamber);
        }

        return new VoteCollection($votes);
    }

    public function representatives(): LegislatorCollection
    {
        $legislators = [];

        foreach ($this->itemsFor('members') as [$item, $chamber]) {
            $legislators[] = PalegisMapper::legislator($item, $chamber);
        }

        return new LegislatorCollection($legislators);
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
