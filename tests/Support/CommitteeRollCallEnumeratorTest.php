<?php

namespace WiserWebSolutions\LaravelPalegis\Tests\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use WiserWebSolutions\LaravelPalegis\Support\CommitteeRollCallEnumerator;
use WiserWebSolutions\LaravelPalegis\Tests\TestCase;

class CommitteeRollCallEnumeratorTest extends TestCase
{
    private function enumerator(int $maxConsecutiveMisses = 2, int $hardCeiling = 5000): CommitteeRollCallEnumerator
    {
        return new CommitteeRollCallEnumerator(
            cache: Cache::store('array'),
            maxConsecutiveMisses: $maxConsecutiveMisses,
            request: ['timeout' => 5, 'retry_times' => 0, 'retry_sleep_ms' => 0],
            hardCeiling: $hardCeiling,
        );
    }

    /**
     * A trimmed committee roll call page, mirroring
     * CommitteeRollCallPageParserTest's fixture.
     */
    private function page(string $billNumber): string
    {
        return <<<HTML
        <a href='/house/committees/64/housing-and-community-development' class='committee '>Housing & Community Development</a>
        <a href=" /house/committees/roll-call-votes/vote-list?rollcalldate=2026-04-13&committeecode=64&sessyr=2025">April 13, 2026</a>
        <a href='/legislation/bills/2025/hb{$billNumber}'>HB {$billNumber}</a>
        <li class="list-group-item">
            <a href=" /house/members/bio/1825/rep-brandon-markosek">Rep. Brandon Markosek</a>
            <span class="badge text-bg-success" title="Yea"></span>
        </li>
        HTML;
    }

    public function test_walk_yields_records_scoped_to_one_committee(): void
    {
        Http::fake([
            'www.palegis.us/house/committees/roll-call-votes/vote-list/vote-summary*committeecode=64&rollcallid=1' => Http::response($this->page('1')),
            'www.palegis.us/house/committees/roll-call-votes/vote-list/vote-summary*' => Http::response('', 404),
        ]);

        $records = iterator_to_array($this->enumerator()->walk('house', '2025_0', '64'));

        $this->assertCount(1, $records);
        $this->assertSame(1, $records[0]['rc_num']);
        $this->assertSame('64', $records[0]['committee_code']);
        $this->assertSame('Housing & Community Development', $records[0]['committee']);
    }

    public function test_walk_all_covers_every_committee_independently(): void
    {
        Http::fake([
            'www.palegis.us/house/committees/roll-call-votes/vote-list/vote-summary*committeecode=64&rollcallid=1' => Http::response($this->page('1')),
            'www.palegis.us/house/committees/roll-call-votes/vote-list/vote-summary*committeecode=62&rollcallid=1' => Http::response($this->page('2')),
            'www.palegis.us/house/committees/roll-call-votes/vote-list/vote-summary*' => Http::response('', 404),
        ]);

        $committees = [
            ['code' => '64', 'slug' => 'housing', 'name' => 'Housing'],
            ['code' => '62', 'slug' => 'appropriations', 'name' => 'Appropriations'],
        ];

        $records = iterator_to_array($this->enumerator()->walkAll('house', '2025_0', $committees));

        $this->assertCount(2, $records);
        $this->assertSame('64', $records[0]['committee_code']);
        $this->assertSame('62', $records[1]['committee_code']);
    }

    public function test_a_completed_committee_roll_call_is_cached_forever(): void
    {
        Http::fake([
            'www.palegis.us/house/committees/roll-call-votes/vote-list/vote-summary*committeecode=64&rollcallid=1' => Http::response($this->page('1')),
            'www.palegis.us/house/committees/roll-call-votes/vote-list/vote-summary*' => Http::response('', 404),
        ]);

        iterator_to_array($this->enumerator()->walk('house', '2025_0', '64'));

        Http::fake([
            'www.palegis.us/*' => Http::response('boom', 500),
        ]);

        $records = iterator_to_array($this->enumerator()->walk('house', '2025_0', '64', startAt: 1));

        $this->assertSame(1, $records[0]['rc_num']);
    }

    public function test_a_different_committee_code_for_the_same_rollcallid_does_not_reuse_the_cache(): void
    {
        Http::fake([
            'www.palegis.us/house/committees/roll-call-votes/vote-list/vote-summary*committeecode=64&rollcallid=1' => Http::response($this->page('1')),
            'www.palegis.us/house/committees/roll-call-votes/vote-list/vote-summary*committeecode=62&rollcallid=1' => Http::response($this->page('2')),
            'www.palegis.us/house/committees/roll-call-votes/vote-list/vote-summary*' => Http::response('', 404),
        ]);

        $one = iterator_to_array($this->enumerator()->walk('house', '2025_0', '64'));
        $two = iterator_to_array($this->enumerator()->walk('house', '2025_0', '62'));

        $this->assertSame(['year' => '2025', 'body' => 'H', 'type' => 'B', 'number' => '1'], $one[0]['bill']);
        $this->assertSame(['year' => '2025', 'body' => 'H', 'type' => 'B', 'number' => '2'], $two[0]['bill']);
    }
}
