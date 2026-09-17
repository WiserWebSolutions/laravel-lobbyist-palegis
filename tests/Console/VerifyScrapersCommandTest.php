<?php

namespace WiserWebSolutions\LaravelPalegis\Tests\Console;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\LaravelPalegis\Tests\TestCase;

class VerifyScrapersCommandTest extends TestCase
{
    private function sessionDaysPage(): string
    {
        return '<a class="dateBtn" aria-label="link for 07/23/2026" href="?SessDate=07/23/2026">23</a>';
    }

    private function membersPage(): string
    {
        return <<<'HTML'
        <div class="col-6 col-sm-6 col-lg-3 member mb-4" data-name="Abney Aerion" data-county="ALLEGHENY" data-party="D" data-district="19" data-leadership="">
            <span class="thumb-info shadow-lg bg-white rounded-bottom h-100">
                <a href=" /house/members/bio/1933/rep-aerion-abney" class="thumb-info-wrapper">
                    <img src="https://www.palegis.us/resources/images/members/200/1933.jpg" class="card-img-top" alt="Photo">
                    <span class="thumb-info-title">
                        <span class="thumb-info-inner">Aerion Abney</span>
                    </span>
                </a>
                <div class="thumb-info-caption px-1"></div>
            </span>
        </div>
        HTML;
    }

    private function committeeListPage(): string
    {
        return "<a href='/house/committees/64/housing-and-community-development' class='committee h4'>Housing & Community Development</a>";
    }

    private function floorRollCallPage(): string
    {
        return <<<'HTML'
        <a href="/house/roll-calls?sessYr=2025&sessInd=0&date=2026-07-23">Thursday Jul 23, 2026</a> 3:07 PM
        <div class="rc-member d-flex align-items-center">
            <a href="/house/members/bio/1933/rep-aerion-abney" target="_self">Rep. Aerion Abney</a>
            <span class="badge text-bg-success" title="Yea"></span>
            <div class="rc-member-print"></div>
        </div>
        HTML;
    }

    private function committeeRollCallPage(): string
    {
        return <<<'HTML'
        <a href='/house/committees/64/housing-and-community-development' class='committee '>Housing & Community Development</a>
        <li class="list-group-item">
            <a href=" /house/members/bio/1825/rep-brandon-markosek">Rep. Brandon Markosek</a>
            <span class="badge text-bg-success" title="Yea"></span>
        </li>
        HTML;
    }

    private function fakeEveryPageAsHealthy(): void
    {
        Http::fake([
            'www.palegis.us/house/session*' => Http::response($this->sessionDaysPage()),
            'www.palegis.us/senate/session*' => Http::response($this->sessionDaysPage()),
            'www.palegis.us/house/members' => Http::response($this->membersPage()),
            'www.palegis.us/senate/members' => Http::response($this->membersPage()),
            'www.palegis.us/house/committees/committee-list' => Http::response($this->committeeListPage()),
            'www.palegis.us/senate/committees/committee-list' => Http::response($this->committeeListPage()),
            'www.palegis.us/house/roll-calls/summary*' => Http::response($this->floorRollCallPage()),
            'www.palegis.us/house/committees/roll-call-votes/*' => Http::response($this->committeeRollCallPage()),
        ]);
    }

    public function test_command_succeeds_when_every_page_still_parses(): void
    {
        $this->fakeEveryPageAsHealthy();

        $this->artisan('palegis:verify-scrapers')
            ->assertSuccessful()
            ->expectsOutputToContain('Every scraper still parses real content.');
    }

    public function test_command_fails_when_a_page_parses_to_nothing(): void
    {
        // Redesigned: no more .member cards on the page. Registered before
        // fakeEveryPageAsHealthy() -- Http::fake() matches the first
        // registered pattern for a given URL, so this must take precedence
        // over that helper's own (unrelated) stub for the same address.
        Http::fake([
            'www.palegis.us/house/members' => Http::response('<html><body>redesigned</body></html>'),
        ]);
        $this->fakeEveryPageAsHealthy();

        $this->artisan('palegis:verify-scrapers')
            ->assertFailed()
            ->expectsOutputToContain('scraper(s) found nothing');
    }
}
