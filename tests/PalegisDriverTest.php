<?php

namespace WiserWebSolutions\LaravelPalegis\Tests;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\LaravelPalegis;
use WiserWebSolutions\LaravelPalegis\PalegisDriver;
use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Data\Legislator;
use WiserWebSolutions\Lobbyist\Data\Vote;
use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Enums\StateEnum;
use WiserWebSolutions\Lobbyist\Exceptions\UnsupportedOperationException;

class PalegisDriverTest extends TestCase
{
    private function driver(): PalegisDriver
    {
        return new PalegisDriver(new LaravelPalegis);
    }

    public function test_list_bills_maps_house_feed_to_dtos(): void
    {
        Http::fake([
            'www.palegis.us/house/rss/session/bills' => Http::response(
                $this->rssXml([
                    ['title' => 'HB 100 - An Act', 'link' => 'https://www.palegis.us/bill/HB100', 'guid' => 'hb100'],
                    ['title' => 'HB 200 - Another Act', 'link' => 'https://www.palegis.us/bill/HB200', 'guid' => 'hb200'],
                ])
            ),
            // Senate has no "bills" feed, so it is skipped without a request.
        ]);

        $bills = $this->driver()->setStateContext('PA')->listBills();

        $this->assertCount(2, $bills);
        $this->assertContainsOnlyInstancesOf(Bill::class, $bills);
        $this->assertSame('HB100', $bills->first()->number);
        $this->assertSame(Chamber::House, $bills->first()->chamber);
        $this->assertSame(StateEnum::PA, $bills->first()->state);
    }

    public function test_list_votes_merges_both_chambers(): void
    {
        Http::fake([
            'www.palegis.us/house/rss/session/votes' => Http::response(
                $this->rssXml([['title' => 'House Vote on HB100', 'guid' => 'hv1']])
            ),
            'www.palegis.us/senate/rss/session/votes' => Http::response(
                $this->rssXml([['title' => 'Senate Vote on SB200', 'guid' => 'sv1']])
            ),
        ]);

        $votes = $this->driver()->setStateContext('PA')->listVotes();

        $this->assertCount(2, $votes);
        $this->assertContainsOnlyInstancesOf(Vote::class, $votes);
        $this->assertSame(Chamber::House, $votes->byChamber(Chamber::House)->first()->chamber);
        $this->assertSame(Chamber::Senate, $votes->byChamber(Chamber::Senate)->first()->chamber);
    }

    public function test_list_representatives_maps_members_feed(): void
    {
        Http::fake([
            'www.palegis.us/house/rss/session/members' => Http::response(
                $this->rssXml([
                    ['title' => 'Rep. Jane Doe', 'link' => 'https://www.palegis.us/member/1', 'guid' => 'm1'],
                ])
            ),
        ]);

        $reps = $this->driver()->setStateContext('PA')->listRepresentatives();

        $this->assertCount(1, $reps);
        $this->assertContainsOnlyInstancesOf(Legislator::class, $reps);
        $this->assertSame('Rep. Jane Doe', $reps->first()->name);
        $this->assertSame(Chamber::House, $reps->first()->chamber);
    }

    public function test_list_sessions_returns_synthetic_pa_session(): void
    {
        $sessions = $this->driver()->setStateContext('PA')->listSessions();

        $this->assertCount(1, $sessions);
        $this->assertSame(StateEnum::PA, $sessions->first()->state);
    }

    public function test_unsupported_lookups_throw(): void
    {
        $driver = $this->driver();

        $this->expectException(UnsupportedOperationException::class);
        $driver->getBill('HB100');
    }

    public function test_invalid_xml_throws_palegis_exception(): void
    {
        Http::fake([
            'www.palegis.us/house/rss/session/bills' => Http::response('this is not xml'),
        ]);

        $this->expectException(PalegisException::class);
        $this->expectExceptionMessageMatches('/Invalid XML/');

        $this->driver()->setStateContext('PA')->listBills();
    }
}
