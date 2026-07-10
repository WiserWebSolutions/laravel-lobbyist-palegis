<?php

namespace WiserWebSolutions\LaravelPalegis\Tests;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\LaravelPalegis;
use WiserWebSolutions\LaravelPalegis\PalegisDriver;
use WiserWebSolutions\Lobbyist\Data\BillText;
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

        $votes = $this->driver()->setStateContext('PA')->votes();

        $this->assertCount(2, $votes);
        $this->assertContainsOnlyInstancesOf(Vote::class, $votes);
        $this->assertSame(Chamber::House, $votes->byChamber(Chamber::House)->first()->chamber);
        $this->assertSame(Chamber::Senate, $votes->byChamber(Chamber::Senate)->first()->chamber);
    }

    public function test_list_representatives_merges_both_chambers(): void
    {
        Http::fake([
            'www.palegis.us/house/rss/session/members' => Http::response(
                $this->rssXml([
                    ['title' => 'Rep. Jane Doe', 'link' => 'https://www.palegis.us/member/1', 'guid' => 'm1'],
                ])
            ),
            'www.palegis.us/senate/rss/session/members' => Http::response(
                $this->rssXml([
                    ['title' => 'Sen. John Roe', 'link' => 'https://www.palegis.us/member/2', 'guid' => 'm2'],
                ])
            ),
        ]);

        $reps = $this->driver()->setStateContext('PA')->representatives();

        $this->assertCount(2, $reps);
        $this->assertContainsOnlyInstancesOf(Legislator::class, $reps);
        $this->assertSame('Rep. Jane Doe', $reps->byChamber(Chamber::House)->first()->name);
        $this->assertSame('Sen. John Roe', $reps->byChamber(Chamber::Senate)->first()->name);
    }

    public function test_list_sessions_returns_synthetic_pa_session(): void
    {
        $sessions = $this->driver()->setStateContext('PA')->sessions();

        $this->assertCount(1, $sessions);
        $this->assertSame(StateEnum::PA, $sessions->first()->state);
    }

    public function test_bill_text_history_maps_printers_numbers_from_the_bill_history_record(): void
    {
        $this->fakeBillHistory();

        $history = $this->driver()->setStateContext('PA')->billTextHistory('HB17');

        $this->assertCount(1, $history);
        $this->assertContainsOnlyInstancesOf(BillText::class, $history);
        $this->assertStringContainsString('HB0017/PN0002', $history->first()->url);
        $this->assertSame('01/08/25', $history->first()->date?->format('m/d/y'));
        $this->assertNull($history->first()->content);
    }

    public function test_bill_text_returns_the_latest_version(): void
    {
        $this->fakeBillHistory();

        $text = $this->driver()->setStateContext('PA')->billText('HB17');

        $this->assertInstanceOf(BillText::class, $text);
        $this->assertStringContainsString('HB0017/PN0002', $text->url);
    }

    public function test_bill_text_throws_when_the_bill_has_no_printers_numbers(): void
    {
        $this->fakeBillHistory();

        $this->expectException(PalegisException::class);
        $this->expectExceptionMessageMatches('/no text versions/');

        $this->driver()->setStateContext('PA')->billText('SB100');
    }

    public function test_unsupported_lookups_throw(): void
    {
        $driver = $this->driver();

        $this->expectException(UnsupportedOperationException::class);
        $driver->vote(1);
    }

    public function test_invalid_xml_throws_palegis_exception(): void
    {
        Http::fake([
            'www.palegis.us/*' => Http::response('this is not xml'),
        ]);

        $this->expectException(PalegisException::class);
        $this->expectExceptionMessageMatches('/Invalid RSS response/');

        $this->driver()->setStateContext('PA')->votes();
    }

    public function test_failed_feed_request_throws_with_attempted_url(): void
    {
        Http::fake([
            'www.palegis.us/*' => Http::response('boom', 500),
        ]);

        try {
            $this->driver()->setStateContext('PA')->votes();
            $this->fail('Expected a PalegisException.');
        } catch (PalegisException $e) {
            $this->assertStringContainsString('www.palegis.us', $e->getMessage());
            $this->assertStringContainsString('HTTP status 500', $e->getMessage());
        }
    }
}
