<?php

namespace WiserWebSolutions\LaravelPalegis\Tests;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\LaravelPalegis\LaravelPalegis;
use WiserWebSolutions\LaravelPalegis\PalegisDriver;
use WiserWebSolutions\LaravelPalegis\Support\PalegisSessionArchive;
use WiserWebSolutions\Lobbyist\Contracts\DatasetArchive;
use WiserWebSolutions\Lobbyist\Contracts\Providers\DatasetLookup;
use WiserWebSolutions\Lobbyist\Contracts\Providers\DatasetProvider;
use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Data\Legislator;
use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Enums\StateEnum;

/**
 * Exercises the driver as a {@see DatasetProvider}/
 * {@see DatasetLookup}, on top
 * of the same Bill History export and member pages the rest of the driver
 * already reads (see {@see BillHistoryTest} and {@see PalegisDriverTest}).
 */
class DatasetTest extends TestCase
{
    private function driver(): PalegisDriver
    {
        return new PalegisDriver(new LaravelPalegis);
    }

    private function fakeDataPage(): void
    {
        Http::fake([
            'www.palegis.us/data' => Http::response(<<<'HTML'
                <table id="billHistoryDataTable" class="table table-hover">
                    <tbody>
                        <tr>
                            <td>
                                <a href="/data/file?documentType=BillHistoryData&amp;session=2025_0" data-last-updated="09/16/2026 07:31 PM">
                                    2025-2026 Regular Session
                                </a>
                            </td>
                            <td>9/16/2026 7:31 PM</td>
                        </tr>
                    </tbody>
                </table>
                HTML),
        ]);
    }

    /**
     * One member per chamber, matching the sponsors in {@see TestCase::fakeBillHistory()}'s
     * fixture (district 116, House; district 174, House).
     */
    private function fakeRosterForCurrentSession(): void
    {
        $house = <<<'HTML'
        <div class="col-6 col-sm-6 col-lg-3 member mb-4" data-name="Watro Dane" data-county="LUZERNE" data-party="R" data-district="116" data-leadership="">
            <span class="thumb-info shadow-lg bg-white rounded-bottom h-100" style="max-width:200px;">
                <a href=" /house/members/bio/2029/rep-watro" class="thumb-info-wrapper">
                        <img src="https://www.palegis.us/resources/images/members/200/2029.jpg" class="card-img-top" alt="Photo">
                        <span class="thumb-info-title">
                            <span class="thumb-info-inner">Dane Watro</span>
                        </span>
                </a>
                <div class="thumb-info-caption px-1"></div>
            </span>
        </div>
        HTML;

        $senate = <<<'HTML'
        <div class="col-6 col-sm-6 col-lg-3 member mb-4" data-name="Roe John" data-county="TEST" data-party="D" data-district="1" data-leadership="">
            <span class="thumb-info shadow-lg bg-white rounded-bottom h-100" style="max-width:200px;">
                <a href=" /senate/members/bio/1187/sen-roe" class="thumb-info-wrapper">
                        <img src="https://www.palegis.us/resources/images/members/200/1187.jpg" class="card-img-top" alt="Photo">
                        <span class="thumb-info-title">
                            <span class="thumb-info-inner">John Roe</span>
                        </span>
                </a>
                <div class="thumb-info-caption px-1"></div>
            </span>
        </div>
        HTML;

        Http::fake([
            'www.palegis.us/house/members?SessYr=2025' => Http::response($house),
            'www.palegis.us/senate/members?SessYr=2025' => Http::response($senate),
        ]);
    }

    public function test_datasets_lists_the_published_sessions(): void
    {
        $this->fakeDataPage();

        $datasets = $this->driver()->setStateContext('PA')->datasets();

        $this->assertCount(1, $datasets);
        $dataset = $datasets->first();
        $this->assertSame('2025_0', $dataset->sessionId);
        $this->assertSame('2025-2026 Regular Session', $dataset->sessionName);
        $this->assertSame(StateEnum::PA, $dataset->state);
        $this->assertSame(2025, $dataset->yearStart);
        $this->assertSame(2026, $dataset->yearEnd);
        $this->assertSame('09/16/2026 07:31 PM', $dataset->hash);
    }

    public function test_dataset_opens_a_dataset_archive(): void
    {
        $this->fakeDataPage();
        $this->fakeRosterForCurrentSession();
        $this->fakeBillHistory();

        $archive = $this->driver()->setStateContext('PA')->dataset('2025_0');

        $this->assertInstanceOf(DatasetArchive::class, $archive);
        $this->assertInstanceOf(PalegisSessionArchive::class, $archive);
        $this->assertSame('2025_0', $archive->dataset()->sessionId);
    }

    public function test_archive_counts_reports_bills_and_people(): void
    {
        $this->fakeDataPage();
        $this->fakeRosterForCurrentSession();
        $this->fakeBillHistory();

        $counts = $this->driver()->setStateContext('PA')->dataset('2025_0')->counts();

        $this->assertSame(2, $counts['bills']);
        $this->assertSame(0, $counts['votes']);
        $this->assertSame(2, $counts['people']);
    }

    public function test_archive_bills_streams_mapped_bills_with_full_detail(): void
    {
        $this->fakeDataPage();
        $this->fakeRosterForCurrentSession();
        $this->fakeBillHistory();

        $bills = $this->driver()->setStateContext('PA')->dataset('2025_0')->bills()->all();

        $this->assertCount(2, $bills);
        $this->assertContainsOnlyInstancesOf(Bill::class, $bills);

        $hb = collect($bills)->first(fn (Bill $bill) => $bill->number === 'HB17');
        // Full detail (raw + sponsors), unlike PalegisDriver::bills()'s summary.
        $this->assertArrayHasKey('raw', $hb->meta);
        $this->assertNotEmpty($hb->referrals());
    }

    public function test_archive_bills_resolves_sponsors_against_the_session_roster(): void
    {
        $this->fakeDataPage();
        $this->fakeRosterForCurrentSession();
        $this->fakeBillHistory();

        $bills = $this->driver()->setStateContext('PA')->dataset('2025_0')->bills()->all();
        $hb = collect($bills)->first(fn (Bill $bill) => $bill->number === 'HB17');

        // The fixture's HB17 sponsor is district 116 (House), matching the
        // fake roster's Dane Watro (member id 2029).
        $this->assertSame('2029', $hb->sponsors()->first()->id);
    }

    public function test_archive_votes_walks_both_chambers_floor_roll_calls(): void
    {
        $this->fakeDataPage();
        $this->fakeRosterForCurrentSession();
        $this->fakeBillHistory();

        config([
            'palegis.cache.store' => 'array',
            'palegis.roll_calls.max_consecutive_misses' => 1,
        ]);

        $rollCallPage = <<<'HTML'
        <a href="/house/roll-calls?sessYr=2025&sessInd=0&date=2026-07-23">Thursday Jul 23, 2026</a> 3:07 PM
        <a href="/legislation/bills/2025/hb17">House Bill 17</a>
        <div class="rc-member d-flex align-items-center">
            <a href="/house/members/bio/2029/rep-watro" target="_self">Rep. Dane Watro</a>
            <span class="badge bg-party-R">R</span> House District&nbsp;116
            <span class="badge text-bg-success" title="Yea"></span>
            <div class="rc-member-print"></div>
        </div>
        HTML;

        Http::fake([
            'www.palegis.us/house/roll-calls/summary*rcNum=1' => Http::response($rollCallPage),
            'www.palegis.us/*/roll-calls/summary*' => Http::response('', 404),
            // No committee list published for either chamber in this
            // fixture -- the committee-vote walk should degrade gracefully
            // (see PalegisSessionArchive::votes()) rather than sink the
            // whole stream, leaving the one floor vote above intact.
            'www.palegis.us/*/committees/committee-list' => Http::response('', 404),
        ]);

        $votes = $this->driver()->setStateContext('PA')->dataset('2025_0')->votes()->all();

        $this->assertCount(1, $votes);
        $this->assertSame('house:1', $votes[0]->id);
        $this->assertSame('20250HB0017', $votes[0]->billId);
        $this->assertSame(Chamber::House, $votes[0]->chamber);
        $this->assertSame('2029', $votes[0]->positions()->first()->legislatorId);
    }

    public function test_archive_votes_includes_committee_roll_calls(): void
    {
        $this->fakeDataPage();
        $this->fakeRosterForCurrentSession();
        $this->fakeBillHistory();

        config([
            'palegis.cache.store' => 'array',
            'palegis.roll_calls.max_consecutive_misses' => 1,
        ]);

        $committeeListPage = <<<'HTML'
        <a href='/house/committees/64/housing-and-community-development' class='committee h4'>Housing & Community Development</a>
        HTML;

        $committeeVotePage = <<<'HTML'
        <a href='/house/committees/64/housing-and-community-development' class='committee '>Housing & Community Development</a>
        <a href=" /house/committees/roll-call-votes/vote-list?rollcalldate=2026-04-13&committeecode=64&sessyr=2025">April 13, 2026</a>
        <a href='/legislation/bills/2025/hb2367'>HB 2367</a>
        <li class="list-group-item">
            <a href=" /house/members/bio/1825/rep-brandon-markosek">Rep. Brandon Markosek</a>
            <span class="badge text-bg-success" title="Yea"></span>
        </li>
        HTML;

        Http::fake([
            'www.palegis.us/*/roll-calls/summary*' => Http::response('', 404),
            'www.palegis.us/house/committees/committee-list' => Http::response($committeeListPage),
            'www.palegis.us/senate/committees/committee-list' => Http::response('', 404),
            'www.palegis.us/house/committees/roll-call-votes/vote-list/vote-summary*committeecode=64&rollcallid=1' => Http::response($committeeVotePage),
            'www.palegis.us/*/committees/roll-call-votes/vote-list/vote-summary*' => Http::response('', 404),
        ]);

        $votes = $this->driver()->setStateContext('PA')->dataset('2025_0')->votes()->all();

        $this->assertCount(1, $votes);
        $this->assertSame('committee:house:64:1', $votes[0]->id);
        $this->assertSame('Housing & Community Development', $votes[0]->committee);
        $this->assertSame('20250HB2367', $votes[0]->billId);
    }

    public function test_archive_people_matches_the_session_roster(): void
    {
        $this->fakeDataPage();
        $this->fakeRosterForCurrentSession();
        $this->fakeBillHistory();

        $people = $this->driver()->setStateContext('PA')->dataset('2025_0')->people()->all();

        $this->assertCount(2, $people);
        $this->assertContainsOnlyInstancesOf(Legislator::class, $people);
        $this->assertSame(Chamber::House, $people[0]->chamber);
    }

    public function test_bill_changes_reuses_the_summary_listing_with_change_hashes(): void
    {
        $this->fakeBillHistory();

        $changes = $this->driver()->setStateContext('PA')->billChanges();

        $this->assertCount(2, $changes);
        $this->assertSame('May 13, 2026 12:05:00 PM EDT', $changes->first()->changeHash);
    }
}
