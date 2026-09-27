<?php

namespace WiserWebSolutions\LaravelPalegis\Tests;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\LaravelPalegis;
use WiserWebSolutions\LaravelPalegis\Support\CosponsorshipMemoParser;

class CosponsorshipMemosTest extends TestCase
{
    public function test_discovers_historical_and_special_sessions(): void
    {
        $sessions = CosponsorshipMemoParser::sessions($this->fixture('memo-sessions'));

        $this->assertContains('2009_1', array_column($sessions, 'id'));
        $this->assertSame('2025_0', collect($sessions)->firstWhere('current', true)['id']);
    }

    public function test_preserves_multiple_prime_sponsors(): void
    {
        $memo = CosponsorshipMemoParser::memo($this->fixture('memo-house-multiple-authors'), 'house', '49285');

        $this->assertCount(2, $memo['members']);
    }

    public function test_reads_real_empty_and_special_session_searches(): void
    {
        $empty = CosponsorshipMemoParser::index($this->fixture('memo-index-empty'), 'house', '2009_1');
        $special = CosponsorshipMemoParser::index($this->fixture('memo-index-special'), 'house', '2009_1');

        $this->assertSame(0, $empty['total']);
        $this->assertSame('4685', $special['items'][0]['id']);
        $this->assertSame('2010-08-04T04:00:00+00:00', $special['items'][0]['circulated_at']);
        $this->assertSame('102', $special['items'][0]['members'][0]['id']);
    }

    public function test_retains_pdf_only_memos_as_metadata_and_a_link(): void
    {
        $index = CosponsorshipMemoParser::index($this->fixture('memo-index-special'), 'house', '2009_1');
        Http::fake(['*/memo?memoID=4685' => Http::response('PDF content must not be stored', 200, ['Content-Type' => 'application/pdf'])]);

        $memo = app(LaravelPalegis::class)->getCosponsorshipMemo('house', '4685', indexRecord: $index['items'][0]);

        $this->assertSame('pdf', $memo['content_format']);
        $this->assertSame('2009_1', $memo['session']);
        $this->assertSame('', $memo['body']);
        $this->assertSame('', $memo['body_html']);
        $this->assertCount(1, $memo['attachments']);
        Http::assertSentCount(1);
    }

    public function test_reads_full_memos_without_inventing_bill_links_from_the_body(): void
    {
        $memo = CosponsorshipMemoParser::memo($this->fixture('memo-house-pending'), 'house', '49286');

        $this->assertSame('2025_0', $memo['session']);
        $this->assertSame('2026-09-25T14:02:00+00:00', $memo['circulated_at']);
        $this->assertSame('1642', $memo['members'][0]['id']);
        $this->assertSame([], $memo['legislation']);
        $this->assertStringContainsString('SB 1376', $memo['body']);
        $this->assertSame(1, substr_count($memo['body'], 'In the near future'));
        $this->assertStringNotContainsString('Generated', $memo['body']);
        $this->assertSame('2026-09-25T14:04:00+00:00', $memo['updated_at']);
    }

    public function test_reads_senate_and_historical_memos(): void
    {
        $senate = CosponsorshipMemoParser::memo($this->fixture('memo-senate-pending'), 'senate', '49290');
        $historical = CosponsorshipMemoParser::memo($this->fixture('memo-house-attachment'), 'house', '42686');

        $this->assertSame('senate', $senate['chamber']);
        $this->assertNotEmpty($senate['body']);
        $this->assertSame('2023_0', $historical['session']);
        $this->assertCount(1, $historical['attachments']);
        $this->assertSame('HB2384', $historical['legislation'][0]['number']);
    }

    public function test_preserves_multiple_bills_and_attachment_links_without_downloading_them(): void
    {
        Http::fake(['*/house/co-sponsorship/memo?memoID=49076' => Http::response($this->fixture('memo-house-introduced'))]);

        $memo = app(LaravelPalegis::class)->getCosponsorshipMemo('house', '49076');

        $this->assertSame(['HB2742', 'HB2741'], array_column($memo['legislation'], 'number'));
        $this->assertCount(2, $memo['attachments']);
        $this->assertCount(2, $memo['documents']);
        Http::assertSentCount(1);
    }

    public function test_search_reports_counts_and_filters_the_requested_session(): void
    {
        Http::fake(['*/search-results*' => Http::response($this->fixture('memo-index-house'))]);

        $index = app(LaravelPalegis::class)->getCosponsorshipMemoIndex('house', '2025_0', '2026-08-03', '2026-08-03');

        $this->assertSame(2, $index['total']);
        $this->assertFalse($index['truncated']);
        $this->assertCount(2, $index['items']);
        $this->assertNotEmpty($index['member_ids']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sessYr=2025&sessInd=0&dateStart=2026-08-03&dateEnd=2026-08-03'));
    }

    public function test_reports_truncation_and_recognizes_a_genuinely_empty_search(): void
    {
        $html = '<main><input name="sessYr" value="2025"><input name="sessInd" value="0">Showing <b>1</b> of <b>251</b> results. Maximum result limit reached.'
            .'<div data-memoid="1"><a href="/house/co-sponsorship/memo?memoID=1">Subject</a></div></main>';
        $index = CosponsorshipMemoParser::index($html, 'house', '2025_0');
        $empty = CosponsorshipMemoParser::index('<main><input name="sessYr" value="2025"><input name="sessInd" value="0">Showing 0 results</main>', 'house', '2025_0');

        $this->assertTrue($index['truncated']);
        $this->assertSame(251, $index['total']);
        $this->assertSame([], $empty['items']);
    }

    public function test_detects_truncation_without_a_maximum_limit_banner(): void
    {
        $html = '<main><input name="sessYr" value="2023"><input name="sessInd" value="0">Showing <b>1</b> of <b>801</b> results.'
            .'<div data-memoid="39345"><a href="/house/co-sponsorship/memo?memoID=39345">Subject</a></div></main>';

        $index = CosponsorshipMemoParser::index($html, 'house', '2023_0');

        $this->assertTrue($index['truncated']);
        $this->assertSame(801, $index['total']);
        $this->assertCount(1, $index['items']);
    }

    public function test_rejects_missing_displayed_records_even_when_the_page_is_truncated(): void
    {
        $this->expectException(PalegisException::class);
        CosponsorshipMemoParser::index(
            '<main><input name="sessYr" value="2023"><input name="sessInd" value="0">Showing 2 of 801 results. Maximum result limit reached.'
            .'<div data-memoid="39345"><a href="/house/co-sponsorship/memo?memoID=39345">Subject</a></div></main>',
            'house', '2023_0',
        );
    }

    public function test_zero_ttl_bypasses_an_existing_cache_entry(): void
    {
        config(['palegis.cache.enabled' => true, 'palegis.cache.store' => 'array']);
        $old = $this->fixture('memo-house-pending');
        Http::fake(['*/memo?memoID=49286' => Http::sequence()->push($old)->push(str_replace('Ensuring Proper Safeguards', 'Updated Safeguards', $old))]);
        $client = app(LaravelPalegis::class);

        $client->getCosponsorshipMemo('house', '49286');
        $memo = $client->getCosponsorshipMemo('house', '49286', ttl: 0);

        $this->assertStringStartsWith('Updated Safeguards', $memo['subject']);
        Http::assertSentCount(2);
    }

    public function test_rejects_wrong_session_results(): void
    {
        $this->expectException(PalegisException::class);
        CosponsorshipMemoParser::index($this->fixture('memo-index-house'), 'house', '2023_0');
    }

    public function test_rejects_unrecognized_success_pages(): void
    {
        $this->expectException(PalegisException::class);
        CosponsorshipMemoParser::memo('<html><title>Temporarily unavailable</title></html>', 'house', '1');
    }

    public function test_http_errors_are_not_empty_memos(): void
    {
        Http::fake(['*' => Http::response('Unavailable', 503)]);
        $this->expectException(PalegisException::class);
        app(LaravelPalegis::class)->getCosponsorshipMemo('senate', '1');
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/fixtures/'.$name.'.html');
    }
}
