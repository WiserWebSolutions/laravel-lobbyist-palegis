<?php

namespace WiserWebSolutions\LaravelPalegis\Tests\Support;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
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

    private function listPage(array $numbers, int $count): string
    {
        $links = '';
        foreach ($numbers as $number) {
            $links .= '<a href="/house/committees/roll-call-votes/vote-list/vote-summary?sessind=0&amp;committeecode=64&amp;rollcallid='.$number.'&amp;sessyr=2025">Vote</a>';
        }

        return '<div id="recentVotesWidget">Committee Votes <span>'.$count.'</span></div>'.$links;
    }

    public function test_numbers_reads_the_complete_sparse_index_and_deduplicates_links(): void
    {
        Http::fake(['www.palegis.us/*' => Http::response($this->listPage([1920, 14, 117, 1920], 3))]);

        $this->assertSame([14, 117, 1920], $this->enumerator()->numbers('house', '2025_0', '64'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'viewall=true'));
    }

    public function test_numbers_distinguishes_a_confirmed_empty_index_from_a_redesigned_page(): void
    {
        Http::fake(['www.palegis.us/*' => Http::sequence()->push($this->listPage([], 0))->push('<h1>Unavailable</h1>')]);
        $this->assertSame([], $this->enumerator()->numbers('house', '2025_0', '64'));
        $this->expectException(PalegisException::class);
        $this->expectExceptionMessage('Missing committee vote-list count');
        $this->enumerator()->numbers('house', '2025_0', '64');
    }

    public function test_numbers_rejects_truncated_indexes(): void
    {
        Http::fake(['www.palegis.us/*' => Http::response($this->listPage([14], 101))]);
        $this->expectException(PalegisException::class);
        $this->expectExceptionMessage('count does not match');
        $this->enumerator()->numbers('house', '2025_0', '64');
    }

    public function test_numbers_rejects_a_different_committee_or_session(): void
    {
        Http::fake(['www.palegis.us/*' => Http::response(str_replace('committeecode=64', 'committeecode=62', $this->listPage([14], 1)))]);
        $this->expectException(PalegisException::class);
        $this->expectExceptionMessage('do not match');
        $this->enumerator()->numbers('house', '2025_0', '64');
    }

    public function test_numbers_rejects_a_published_vote_above_the_traversal_ceiling(): void
    {
        Http::fake(['www.palegis.us/*' => Http::response($this->listPage([5001], 1))]);
        $this->expectException(PalegisException::class);
        $this->expectExceptionMessage('safety ceiling');
        $this->enumerator()->numbers('house', '2025_0', '64');
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

    public function test_probe_recognizes_the_sources_explicit_absence_page(): void
    {
        Http::fake(['www.palegis.us/*' => Http::response('<div class="alert">Could not locate this committee vote. New Search</div>')]);
        $this->assertNull($this->enumerator()->probe('house', '2025_0', '64', 2));
    }

    public function test_probe_rejects_unexpected_success_pages_and_service_errors(): void
    {
        foreach ([200, 503] as $status) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['www.palegis.us/*' => Http::response('unavailable', $status)]);
            try {
                $this->enumerator()->probe('house', '2025_0', '64', 2);
                $this->fail('A failed request must not finish a committee stream.');
            } catch (PalegisException $exception) {
                $this->assertNotSame(404, $exception->getCode());
            }
        }
    }
}
