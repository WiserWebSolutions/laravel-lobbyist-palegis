<?php

namespace WiserWebSolutions\LaravelPalegis\Tests\Support;

use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\Support\CommitteeRollCallPageParser;
use WiserWebSolutions\LaravelPalegis\Tests\TestCase;

class CommitteeRollCallPageParserTest extends TestCase
{
    /**
     * A trimmed excerpt of a real committee vote-summary page, mirroring its
     * markup's single-quoted attributes and structure.
     */
    private function page(): string
    {
        return <<<'HTML'
        <a href='/house/committees/64/housing-and-community-development' class='committee '>Housing & Community Development</a>
        <a href=" /house/committees/roll-call-votes/vote-list?rollcalldate=2026-04-13&committeecode=64&sessyr=2025">April 13, 2026</a>
        <a class='' role='link' href='/legislation/bills/2025/hb2367' target='_self'>HB 2367</a>
        <div class="col-lg-6 h6 mb-0 detailsLabel d-flex align-items-center">
            Type of Motion
        </div>
        <div class="col-lg-6 fw-medium">
            Report Bill As Committed
        </div>
        <table class="w-75 mx-auto">
            <tr><th class="text-success-alt">Yeas</th><td class="text-end fw-bold text-muted">14</td></tr>
            <tr><th class="text-danger-alt">Nays</th><td class="text-end fw-bold text-muted">12</td></tr>
            <tr><th class="text-secondary-alt-alt">No Votes</th><td class="text-end fw-bold text-muted">0</td></tr>
        </table>
        <li class="list-group-item py-0 pe-0 overflow-hidden ">
            <div class="row pe-0 py-2 voteRow align-items-center">
                <div class="col-auto fw-medium memberVote flex-grow-1">
                    <a href=" /house/members/bio/1825/rep-brandon-markosek">Rep. Brandon Markosek</a>
                    <span class="badge bg-party-D ms-2 align-self-center">Chair</span>
                </div>
                <div class="col-auto">
                    <span class="badge text-bg-success me-2 d-print-none" title="Yea" data-bs-toggle="tooltip"></span>
                </div>
            </div>
        </li>
        <li class="list-group-item py-0 pe-0 overflow-hidden bg-light">
            <div class="row pe-0 py-2 voteRow align-items-center">
                <div class="col-auto fw-medium memberVote flex-grow-1">
                    <a href=" /house/members/bio/1933/rep-aerion-abney">Rep. Aerion Abney</a>
                </div>
                <div class="col-auto">
                    <span class="badge text-bg-danger me-2 d-print-none" title="Nay" data-bs-toggle="tooltip"></span>
                </div>
            </div>
        </li>
        HTML;
    }

    public function test_parses_the_committee_name(): void
    {
        $result = CommitteeRollCallPageParser::parse($this->page());

        $this->assertSame('Housing & Community Development', $result['committee']);
    }

    public function test_parses_the_date_from_the_rollcalldate_link(): void
    {
        $result = CommitteeRollCallPageParser::parse($this->page());

        $this->assertSame('2026-04-13', $result['date']);
    }

    public function test_parses_the_bill_link_into_its_parts(): void
    {
        $result = CommitteeRollCallPageParser::parse($this->page());

        $this->assertSame(['year' => '2025', 'body' => 'H', 'type' => 'B', 'number' => '2367'], $result['bill']);
    }

    public function test_parses_the_motion(): void
    {
        $result = CommitteeRollCallPageParser::parse($this->page());

        $this->assertSame('Report Bill As Committed', $result['motion']);
    }

    public function test_parses_the_tallies(): void
    {
        $result = CommitteeRollCallPageParser::parse($this->page());

        $this->assertSame(['yea' => 14, 'nay' => 12, 'no_vote' => 0], $result['tallies']);
    }

    public function test_parses_every_member_position_with_no_party_or_district(): void
    {
        $result = CommitteeRollCallPageParser::parse($this->page());

        $this->assertCount(2, $result['positions']);
        $this->assertSame(['id' => '1825', 'name' => 'Brandon Markosek', 'position' => 'Yea'], $result['positions'][0]);
        $this->assertSame('Nay', $result['positions'][1]['position']);
    }

    public function test_throws_when_no_members_are_found(): void
    {
        $this->expectException(PalegisException::class);
        $this->expectExceptionMessageMatches('/No members found/');

        CommitteeRollCallPageParser::parse('<html><body>redesigned or mismatched committeecode</body></html>');
    }
}
