<?php

namespace WiserWebSolutions\LaravelPalegis\Tests\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use WiserWebSolutions\LaravelPalegis\Support\RollCallEnumerator;
use WiserWebSolutions\LaravelPalegis\Tests\TestCase;

class RollCallEnumeratorTest extends TestCase
{
    private function enumerator(int $maxConsecutiveMisses = 3, int $hardCeiling = 5000): RollCallEnumerator
    {
        return new RollCallEnumerator(
            cache: Cache::store('array'),
            maxConsecutiveMisses: $maxConsecutiveMisses,
            request: ['timeout' => 5, 'retry_times' => 0, 'retry_sleep_ms' => 0],
            hardCeiling: $hardCeiling,
        );
    }

    /**
     * A trimmed roll call summary page, mirroring RollCallPageParserTest's
     * fixture (see that test for the full real markup this is based on).
     */
    private function page(string $billNumber, string $position = 'Yea'): string
    {
        return <<<HTML
        <a href="/house/roll-calls?sessYr=2025&sessInd=0&date=2026-07-23">Thursday Jul 23, 2026</a> 3:07 PM
        <a href="/legislation/bills/2025/hb{$billNumber}">House Bill {$billNumber}</a>
        <div class="rc-member d-flex align-items-center">
            <a href="/house/members/bio/1933/rep-aerion-abney" target="_self">Rep. Aerion Abney</a>
            <span class="badge bg-party-D">D</span> House District&nbsp;19
            <span class="badge text-bg-success" title="{$position}"></span>
            <div class="rc-member-print"></div>
        </div>
        HTML;
    }

    public function test_walk_yields_records_with_their_roll_call_number(): void
    {
        Http::fake([
            'www.palegis.us/house/roll-calls/summary*rcNum=1' => Http::response($this->page('1')),
            'www.palegis.us/house/roll-calls/summary*rcNum=2' => Http::response($this->page('2')),
            'www.palegis.us/house/roll-calls/summary*' => Http::response('', 404),
        ]);

        $records = iterator_to_array($this->enumerator()->walk('house', '2025_0'));

        $this->assertCount(2, $records);
        $this->assertSame(1, $records[0]['rc_num']);
        $this->assertSame(['year' => '2025', 'body' => 'H', 'type' => 'B', 'number' => '1'], $records[0]['bill']);
        $this->assertSame(2, $records[1]['rc_num']);
    }

    public function test_walk_stops_after_the_configured_run_of_misses(): void
    {
        Http::fake([
            'www.palegis.us/house/roll-calls/summary*rcNum=1' => Http::response($this->page('1')),
            'www.palegis.us/house/roll-calls/summary*' => Http::response('', 404),
        ]);

        $records = iterator_to_array($this->enumerator(maxConsecutiveMisses: 2)->walk('house', '2025_0'));

        $this->assertCount(1, $records);
        // 1 hit + 2 misses (rcNum 2 and 3) before giving up.
        Http::assertSentCount(3);
    }

    public function test_a_miss_in_the_middle_does_not_end_the_walk(): void
    {
        Http::fake([
            'www.palegis.us/house/roll-calls/summary*rcNum=1' => Http::response($this->page('1')),
            'www.palegis.us/house/roll-calls/summary*rcNum=2' => Http::response('', 404),
            'www.palegis.us/house/roll-calls/summary*rcNum=3' => Http::response($this->page('3')),
            'www.palegis.us/house/roll-calls/summary*' => Http::response('', 404),
        ]);

        $records = iterator_to_array($this->enumerator(maxConsecutiveMisses: 3)->walk('house', '2025_0'));

        $this->assertCount(2, $records);
        $this->assertSame(1, $records[0]['rc_num']);
        $this->assertSame(3, $records[1]['rc_num']);
    }

    public function test_a_completed_roll_call_is_cached_forever_and_not_refetched(): void
    {
        Http::fake([
            'www.palegis.us/house/roll-calls/summary*rcNum=1' => Http::response($this->page('1')),
            'www.palegis.us/house/roll-calls/summary*' => Http::response('', 404),
        ]);

        iterator_to_array($this->enumerator()->walk('house', '2025_0'));
        Http::assertSentCount(1 + 3);

        Http::fake([
            'www.palegis.us/*' => Http::response('boom', 500),
        ]);

        // Re-walking must serve rcNum 1 from cache rather than re-fetching --
        // a completed roll call is history and never changes.
        $records = iterator_to_array($this->enumerator()->walk('house', '2025_0', startAt: 1));

        $this->assertSame(1, $records[0]['rc_num']);
    }

    public function test_the_hard_ceiling_stops_the_walk_even_when_every_request_succeeds(): void
    {
        // A pathological server that never actually misses -- the
        // consecutive-miss counter alone would never end this walk.
        Http::fake([
            'www.palegis.us/house/*' => Http::response($this->page('1')),
        ]);

        $records = iterator_to_array($this->enumerator(maxConsecutiveMisses: 100, hardCeiling: 5)->walk('house', '2025_0'));

        $this->assertCount(5, $records);
        $this->assertSame(5, $records[4]['rc_num']);
    }

    public function test_the_session_is_encoded_in_the_request_url(): void
    {
        Http::fake([
            'www.palegis.us/senate/roll-calls/summary*rcNum=1' => Http::response($this->page('100')),
            'www.palegis.us/senate/*' => Http::response('', 404),
        ]);

        iterator_to_array($this->enumerator(maxConsecutiveMisses: 1)->walk('senate', '2007_1'));

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'sessYr=2007')
            && str_contains((string) $request->url(), 'sessInd=1'));
    }
}
