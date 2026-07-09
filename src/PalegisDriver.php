<?php

namespace WiserWebSolutions\LaravelPalegis;

use WiserWebSolutions\LaravelPalegis\Support\PalegisMapper;
use WiserWebSolutions\Lobbyist\Contracts\Providers\BillProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\RepresentativeProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\SessionProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\VoteProvider;
use WiserWebSolutions\Lobbyist\Data\BillCollection;
use WiserWebSolutions\Lobbyist\Data\LegislatorCollection;
use WiserWebSolutions\Lobbyist\Data\SessionCollection;
use WiserWebSolutions\Lobbyist\Data\VoteCollection;
use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Support\AbstractDriver;

/**
 * Pennsylvania driver backed by the palegis.us RSS feeds.
 *
 * The feeds are browse-only: they publish current bills, votes and members but
 * offer no way to look up an arbitrary identifier, so this driver implements
 * the list/browse provider interfaces but none of the *Lookup interfaces.
 * Calling getBill()/getVote()/getRepresentative() throws
 * {@see UnsupportedOperationException}
 * via {@see AbstractDriver}.
 */
class PalegisDriver extends AbstractDriver implements BillProvider, RepresentativeProvider, SessionProvider, VoteProvider
{
    /** @var array<string, Chamber> */
    private const CHAMBERS = [
        'house' => Chamber::House,
        'senate' => Chamber::Senate,
    ];

    public function __construct(private readonly LaravelPalegis $client) {}

    public function listSessions(): SessionCollection
    {
        return new SessionCollection([
            PalegisMapper::currentSession(),
        ]);
    }

    public function listBills(): BillCollection
    {
        $bills = [];

        foreach ($this->itemsFor('bills') as [$item, $chamber]) {
            $bills[] = PalegisMapper::bill($item, $chamber);
        }

        return new BillCollection($bills);
    }

    public function listVotes(): VoteCollection
    {
        $votes = [];

        foreach ($this->itemsFor('votes') as [$item, $chamber]) {
            $votes[] = PalegisMapper::vote($item, $chamber);
        }

        return new VoteCollection($votes);
    }

    public function listRepresentatives(): LegislatorCollection
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
