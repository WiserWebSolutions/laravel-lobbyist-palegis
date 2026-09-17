<?php

namespace WiserWebSolutions\LaravelPalegis\Tests\Support;

use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\Support\DataPageParser;
use WiserWebSolutions\LaravelPalegis\Tests\TestCase;

class DataPageParserTest extends TestCase
{
    /**
     * A trimmed excerpt of the real `/data` page's Bill History Data table,
     * mirroring its markup and attribute order.
     */
    private function dataPage(): string
    {
        return <<<'HTML'
        <table id="billHistoryDataTable" class="table table-hover">
            <thead>
                <th>Session</th>
                <th data-type="date" date-format="MM/DD/YYYY">Last Updated</th>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <a href="/data/file?documentType=BillHistoryData&amp;session=2025_0" data-last-updated="09/16/2026 07:31 PM">
                            2025-2026 Regular Session
                        </a>
                    </td>
                    <td>9/16/2026 7:31 PM</td>
                </tr>
                <tr>
                    <td>
                        <a href="/data/file?documentType=BillHistoryData&amp;session=2023_1" data-last-updated="12/31/2024 10:20 PM">
                            2023-2024 Special Session #1 (Victims of Sexual Abuse)
                        </a>
                    </td>
                    <td>12/31/2024 10:20 PM</td>
                </tr>
            </tbody>
        </table>
        HTML;
    }

    public function test_parses_every_session_row(): void
    {
        $sessions = DataPageParser::parse($this->dataPage());

        $this->assertCount(2, $sessions);
        $this->assertSame('2025_0', $sessions[0]['session']);
        $this->assertSame('2025-2026 Regular Session', $sessions[0]['name']);
        $this->assertSame(2025, $sessions[0]['year_start']);
        $this->assertSame(2026, $sessions[0]['year_end']);
        $this->assertSame('09/16/2026 07:31 PM', $sessions[0]['last_updated']);
    }

    public function test_a_special_session_still_parses_its_year_range(): void
    {
        $sessions = DataPageParser::parse($this->dataPage());

        $this->assertSame('2023_1', $sessions[1]['session']);
        $this->assertSame(2023, $sessions[1]['year_start']);
        $this->assertSame(2024, $sessions[1]['year_end']);
    }

    public function test_throws_when_the_page_has_no_recognizable_rows(): void
    {
        $this->expectException(PalegisException::class);
        $this->expectExceptionMessageMatches('/No Bill History Data sessions found/');

        DataPageParser::parse('<html><body>redesigned page</body></html>');
    }
}
