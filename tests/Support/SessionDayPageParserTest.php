<?php

namespace WiserWebSolutions\LaravelPalegis\Tests\Support;

use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\Support\SessionDayPageParser;
use WiserWebSolutions\LaravelPalegis\Tests\TestCase;

class SessionDayPageParserTest extends TestCase
{
    /**
     * A trimmed excerpt of a real `/senate/session?days` page: one date-list
     * item per month, each holding one `dateBtn` anchor per session day,
     * mirroring the real markup's whitespace and attribute order.
     */
    private function page(): string
    {
        return <<<'HTML'
        <ul class="list-group date-list">
            <li class="list-group-item ">
                <div class="month">
                    <span class="h6 mb-0 fw-medium text-dark">JANUARY</span>
                </div>
                <div class="d-flex flex-wrap">
                    <a class="dateBtn btn btn-info shadow-lg text-nowrap" role="button" href="/senate/session/info?SessDate=01/06/2026" target="_self" aria-label="link for 01/06/2026">
                        6 NV
                    </a>
                </div>
            </li>
            <li class="list-group-item bg-light">
                <div class="month">
                    <span class="h6 mb-0 fw-medium text-dark">FEBRUARY</span>
                </div>
                <div class="d-flex flex-wrap">
                    <a class="dateBtn btn btn-info shadow-lg text-nowrap" role="button" href="/senate/session/info?SessDate=02/02/2026" target="_self" aria-label="link for 02/02/2026">
                        2
                    </a>
                    <a class="dateBtn btn btn-info shadow-lg text-nowrap" role="button" href="/senate/session/info?SessDate=02/03/2026" target="_self" aria-label="link for 02/03/2026">
                        3
                    </a>
                </div>
            </li>
        </ul>
        HTML;
    }

    public function test_it_extracts_every_date_button(): void
    {
        $rows = SessionDayPageParser::parse($this->page());

        $this->assertCount(3, $rows);
        $this->assertSame(['2026-01-06', '2026-02-02', '2026-02-03'], array_column($rows, 'date'));
    }

    public function test_an_nv_suffix_marks_a_non_voting_day(): void
    {
        $rows = SessionDayPageParser::parse($this->page());

        $this->assertFalse($rows[0]['voting_day']);
        $this->assertTrue($rows[1]['voting_day']);
        $this->assertTrue($rows[2]['voting_day']);
    }

    public function test_the_url_is_resolved_against_the_site_origin(): void
    {
        $rows = SessionDayPageParser::parse($this->page());

        $this->assertSame(
            'https://www.palegis.us/senate/session/info?SessDate=01/06/2026',
            $rows[0]['url'],
        );
    }

    public function test_an_already_absolute_href_is_kept_as_is(): void
    {
        $html = str_replace(
            'href="/senate/session/info?SessDate=01/06/2026"',
            'href="https://other.example/senate/session/info?SessDate=01/06/2026"',
            $this->page(),
        );

        $rows = SessionDayPageParser::parse($html);

        $this->assertSame('https://other.example/senate/session/info?SessDate=01/06/2026', $rows[0]['url']);
    }

    public function test_it_throws_when_the_page_has_no_recognizable_date_buttons(): void
    {
        $this->expectException(PalegisException::class);

        SessionDayPageParser::parse('<html><body>palegis.us redesigned this page</body></html>');
    }

    /**
     * A day scheduled further out but not yet confirmed renders as a disabled
     * <button>, not a linked <a> -- verified against a live page where
     * September/October/November dates months ahead were all disabled
     * buttons while nearer dates were real links. An earlier version of this
     * parser matched only <a href="...SessDate=..."> and silently dropped
     * every one of these, which is exactly the set a forward-looking
     * calendar cares about most.
     */
    public function test_a_disabled_button_for_a_not_yet_confirmed_day_is_still_extracted(): void
    {
        $html = <<<'HTML'
        <div class="d-flex flex-wrap">
            <button class="dateBtn btn btn-info shadow-lg text-nowrap" role="button" aria-label="link for 09/28/2026" disabled>
                28
            </button>
        </div>
        HTML;

        $rows = SessionDayPageParser::parse($html);

        $this->assertCount(1, $rows);
        $this->assertSame('2026-09-28', $rows[0]['date']);
        $this->assertTrue($rows[0]['voting_day']);
    }

    public function test_a_disabled_button_has_no_url_since_nothing_is_linked_yet(): void
    {
        $html = <<<'HTML'
        <button class="dateBtn btn btn-info shadow-lg text-nowrap" role="button" aria-label="link for 09/28/2026" disabled>
            28
        </button>
        HTML;

        $rows = SessionDayPageParser::parse($html);

        $this->assertSame('', $rows[0]['url']);
    }

    public function test_confirmed_and_not_yet_confirmed_days_are_both_extracted_from_the_same_page(): void
    {
        $html = <<<'HTML'
        <a class="dateBtn btn btn-info" href="/senate/session/info?SessDate=07/12/2026" aria-label="link for 07/12/2026">12</a>
        <button class="dateBtn btn btn-info" aria-label="link for 09/28/2026" disabled>28</button>
        HTML;

        $rows = SessionDayPageParser::parse($html);

        $this->assertSame(['2026-07-12', '2026-09-28'], array_column($rows, 'date'));
        $this->assertNotSame('', $rows[0]['url']);
        $this->assertSame('', $rows[1]['url']);
    }
}
