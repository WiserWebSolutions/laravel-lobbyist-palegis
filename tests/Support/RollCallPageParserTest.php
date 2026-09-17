<?php

namespace WiserWebSolutions\LaravelPalegis\Tests\Support;

use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\Support\RollCallPageParser;
use WiserWebSolutions\LaravelPalegis\Tests\TestCase;

class RollCallPageParserTest extends TestCase
{
    /**
     * A trimmed excerpt of a real `/house/roll-calls/summary?...&rcNum=N`
     * page: the details panel, the tally block, and three member rows,
     * mirroring the real markup's structure and attribute order.
     */
    private function page(): string
    {
        return <<<'HTML'
        <div class="card shadow portlet rc-details mb-4">
            <div class="card-body overflow-x-hidden">
                <div class="row mb-3">
                    <div class="col-lg-4">
                        <div class="h6 mb-1 px-2 py-1 bg-light fw-bold">Vote Date</div>
                        <a href="/house/roll-calls?sessYr=2025&sessInd=0&date=2026-07-23">Thursday Jul 23, 2026</a> 3:07 PM
                    </div>
                    <div class="col-lg-4 align-content-stretch">
                        <div class="h6 mb-1 px-2 py-1 bg-light fw-bold">Bill</div>
                        <a href="/legislation/bills/2025/hb1042">House Bill 1042</a>
                        PN 3793
                    </div>
                    <div class="col-lg-4">
                        <div class="h6 mb-1 px-2 py-1 bg-light fw-bold">Action</div>
                        <div>CONCURRENCE</div>
                    </div>
                </div>
            </div>
        </div>
        <div id="voteSummary" class="fs-6">
            <div class="d-flex"><div class="flex-grow-1">Yea</div><div>102</div></div>
            <div class="d-flex"><div class="flex-grow-1">Nay</div><div>100</div></div>
            <div class="d-flex"><div class="flex-grow-1">No Vote</div><div>0</div></div>
            <div class="d-flex"><div class="flex-grow-1">Leave</div><div>1</div></div>
        </div>
        <div class="grid grid-H">
            <div class="rc-member d-flex align-items-center">
                <div class="rc-member-display flex-grow-1 d-print-none">
                    <div class="text-sm-start">
                        <strong><a href="/house/members/bio/1933/rep-aerion-abney" target="_self">Rep. Aerion Abney</a></strong>
                        <div><div><span class="badge bg-party-D">D</span> House District&nbsp;19</div></div>
                    </div>
                </div>
                <div class="d-print-none"><span class="badge text-bg-success" title="Yea"><i class="fa-solid fa-check"></i></span></div>
                <div class="rc-member-print text-success d-none d-print-block">Y</div>
            </div>
            <div class="rc-member d-flex align-items-center">
                <div class="rc-member-display flex-grow-1 d-print-none">
                    <div class="text-sm-start">
                        <strong><a href="/house/members/bio/2029/rep-marc-anderson" target="_self">Rep. Marc Anderson</a></strong>
                        <div><div><span class="badge bg-party-R">R</span> House District&nbsp;116</div></div>
                    </div>
                </div>
                <div class="d-print-none"><span class="badge text-bg-danger" title="Nay"><i class="fa-solid fa-xmark"></i></span></div>
                <div class="rc-member-print text-danger d-none d-print-block">N</div>
            </div>
            <div class="rc-member d-flex align-items-center">
                <div class="rc-member-display flex-grow-1 d-print-none">
                    <div class="text-sm-start">
                        <strong><a href="/house/members/bio/1187/sen-john-roe" target="_self">Rep. John Roe</a></strong>
                        <div><div><span class="badge bg-party-D">D</span> House District&nbsp;1</div></div>
                    </div>
                </div>
                <div class="d-print-none"><span class="badge text-bg-secondary" title="Leave"><i class="fa-solid fa-question"></i></span></div>
                <div class="rc-member-print text-secondary d-none d-print-block">E</div>
            </div>
        </div>
        HTML;
    }

    public function test_parses_the_session_and_date(): void
    {
        $result = RollCallPageParser::parse($this->page());

        $this->assertSame('2025', $result['session_year']);
        $this->assertSame('0', $result['session_index']);
        $this->assertSame('2026-07-23', $result['date']);
        $this->assertSame('3:07 PM', $result['time']);
    }

    public function test_parses_the_bill_link_into_its_parts(): void
    {
        $result = RollCallPageParser::parse($this->page());

        $this->assertSame(['year' => '2025', 'body' => 'H', 'type' => 'B', 'number' => '1042'], $result['bill']);
    }

    public function test_parses_the_action(): void
    {
        $result = RollCallPageParser::parse($this->page());

        $this->assertSame('CONCURRENCE', $result['action']);
    }

    public function test_parses_the_tallies(): void
    {
        $result = RollCallPageParser::parse($this->page());

        $this->assertSame(['yea' => 102, 'nay' => 100, 'no_vote' => 0, 'leave' => 1], $result['tallies']);
    }

    public function test_parses_every_member_position(): void
    {
        $result = RollCallPageParser::parse($this->page());

        $this->assertCount(3, $result['positions']);
        $this->assertSame(['id' => '1933', 'name' => 'Aerion Abney', 'party' => 'D', 'district' => '19', 'position' => 'Yea'], $result['positions'][0]);
        $this->assertSame('Nay', $result['positions'][1]['position']);
        $this->assertSame('2029', $result['positions'][1]['id']);
        $this->assertSame('Leave', $result['positions'][2]['position']);
    }

    public function test_a_procedural_roll_call_with_no_bill_maps_to_null(): void
    {
        $page = str_replace(
            '<a href="/legislation/bills/2025/hb1042">House Bill 1042</a>',
            '',
            $this->page()
        );

        $result = RollCallPageParser::parse($page);

        $this->assertNull($result['bill']);
    }

    public function test_throws_when_no_members_are_found(): void
    {
        $this->expectException(PalegisException::class);
        $this->expectExceptionMessageMatches('/No members found/');

        RollCallPageParser::parse('<html><body>redesigned page</body></html>');
    }
}
