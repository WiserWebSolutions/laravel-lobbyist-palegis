<?php

namespace WiserWebSolutions\LaravelPalegis\Tests;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\LaravelPalegis;
use WiserWebSolutions\LaravelPalegis\PalegisDriver;
use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Enums\StateEnum;

class BillHistoryTest extends TestCase
{
    private function driver(): PalegisDriver
    {
        return new PalegisDriver(new LaravelPalegis);
    }

    /**
     * Fake the Bill History download with a ZIP-wrapped XML fixture (two bills).
     */
    private function fakeBillHistory(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<historyExport exportDate="July 9, 2026 7:30:08 PM EDT" totalDocuments="2">
    <session year="2025" session="0">
        <bill id="20250HB0017" lastUpdate="May 13, 2026 12:05:00 PM EDT">
            <sessionYear>2025</sessionYear>
            <session>0</session>
            <body>H</body>
            <type description="House Bill">B</type>
            <subType>B</subType>
            <number>0017</number>
            <shortTitle>An Act providing for cursive handwriting instruction.</shortTitle>
            <cosponsorshipMemo memoUrl="https://www.palegis.us/house/co-sponsorship/memo?memoID=43567">Mandating Cursive Handwriting</cosponsorshipMemo>
            <sponsors>
                <sponsor sequenceNumber="01" fillSequence="0" party="R" body="H" districtNumber="116">WATRO</sponsor>
                <sponsor sequenceNumber="02" fillSequence="0" party="D" body="H" districtNumber="174">NEILSON</sponsor>
            </sponsors>
            <printersNumberHistory>
                <number sequence="01" billTextPdfUrl="https://www.palegis.us/legislation/bills/text/PDF/2025/0/HB0017/PN0002">0002</number>
            </printersNumberHistory>
            <actionHistory>
                <action sequence="01" actionChamber="H">
                    <verb>Referred to</verb>
                    <committee>EDUCATION</committee>
                    <date>01/08/25</date>
                    <printersNumber>0002</printersNumber>
                    <rollCallVote></rollCallVote>
                    <fullAction>Referred to EDUCATION, Jan. 8, 2025</fullAction>
                </action>
                <action sequence="02" actionChamber="H">
                    <verb>Reported as committed,</verb>
                    <committee>EDUCATION</committee>
                    <date>03/12/25</date>
                    <printersNumber>0002</printersNumber>
                    <rollCallVote></rollCallVote>
                    <fullAction>Reported as committed, March 12, 2025</fullAction>
                </action>
            </actionHistory>
        </bill>
        <bill id="20250SB0100" lastUpdate="Feb 1, 2026 9:00:00 AM EST">
            <sessionYear>2025</sessionYear>
            <session>0</session>
            <body>S</body>
            <type description="Senate Bill">B</type>
            <subType>B</subType>
            <number>0100</number>
            <shortTitle>An Act concerning appropriations.</shortTitle>
            <sponsors></sponsors>
            <printersNumberHistory></printersNumberHistory>
            <actionHistory>
                <action sequence="01" actionChamber="S">
                    <verb>Introduced and referred to</verb>
                    <committee>APPROPRIATIONS</committee>
                    <date>02/01/25</date>
                    <printersNumber>0101</printersNumber>
                    <rollCallVote></rollCallVote>
                    <fullAction>Introduced and referred to APPROPRIATIONS, Feb. 1, 2025</fullAction>
                </action>
            </actionHistory>
        </bill>
    </session>
</historyExport>
XML;

        $zipBytes = $this->zipString('PA-Bill-History-2025-RegularSession.xml', $xml);

        Http::fake([
            'www.palegis.us/data/file*' => Http::response($zipBytes, 200),
        ]);
    }

    /**
     * Build an in-memory ZIP archive containing a single named entry.
     */
    private function zipString(string $entryName, string $contents): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'palegis_test_zip_');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString($entryName, $contents);
        $zip->close();

        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    public function test_get_bill_history_downloads_unzips_and_parses(): void
    {
        $this->fakeBillHistory();

        $history = (new LaravelPalegis)->getBillHistory('2025_0');

        $this->assertSame(2, $history['total']);
        $this->assertCount(2, $history['bills']);

        $first = $history['bills'][0];
        $this->assertSame('20250HB0017', $first['id']);
        $this->assertSame('HB17', $first['designator']);
        $this->assertCount(2, $first['sponsors']);
        $this->assertSame('WATRO', $first['sponsors'][0]['name']);
        $this->assertCount(2, $first['actions']);
        $this->assertSame('Reported as committed, March 12, 2025', end($first['actions'])['full_action']);
    }

    public function test_current_session_auto_detects_regular_session(): void
    {
        $year = (int) date('Y');
        $expectedStart = $year % 2 === 0 ? $year - 1 : $year;

        $this->assertSame($expectedStart.'_0', (new LaravelPalegis)->currentSession());
    }

    public function test_driver_bills_maps_history_to_dtos(): void
    {
        $this->fakeBillHistory();

        $bills = $this->driver()->setStateContext('PA')->bills();

        $this->assertCount(2, $bills);
        $this->assertContainsOnlyInstancesOf(Bill::class, $bills);

        $hb = $bills->first();
        $this->assertSame('HB17', $hb->number);
        $this->assertSame(Chamber::House, $hb->chamber);
        $this->assertSame(StateEnum::PA, $hb->state);
        $this->assertSame('Reported as committed, March 12, 2025', $hb->lastAction);
        $this->assertSame('2025-03-12', $hb->lastActionDate?->format('Y-m-d'));
        $this->assertStringContainsString('HB0017/PN0002', $hb->url);
    }

    public function test_driver_bill_lookup_by_designator_and_id(): void
    {
        $this->fakeBillHistory();
        $driver = $this->driver()->setStateContext('PA');

        $this->assertSame('HB17', $driver->bill('HB17')->number);
        $this->assertSame('HB17', $driver->bill('hb0017')->number);   // case + leading zeros
        $this->assertSame('HB17', $driver->bill('20250HB0017')->number); // full id
        $this->assertSame(Chamber::Senate, $driver->bill('SB100')->chamber);
    }

    public function test_driver_bill_lookup_throws_when_not_found(): void
    {
        $this->fakeBillHistory();

        $this->expectException(PalegisException::class);
        $this->expectExceptionMessageMatches('/not found/');

        $this->driver()->setStateContext('PA')->bill('HB9999');
    }

    public function test_failed_download_throws_with_attempted_url_and_session_hint(): void
    {
        Http::fake([
            'www.palegis.us/data/file*' => Http::response('not found', 404),
        ]);

        try {
            (new LaravelPalegis)->getBillHistory('2099_0');
            $this->fail('Expected a PalegisException.');
        } catch (PalegisException $e) {
            $this->assertStringContainsString('https://www.palegis.us/data/file', $e->getMessage());
            $this->assertStringContainsString('documentType=BillHistoryData', $e->getMessage());
            $this->assertStringContainsString('2099_0', $e->getMessage());
            $this->assertStringContainsString('HTTP status 404', $e->getMessage());
            $this->assertStringContainsString('report', $e->getMessage());
        }
    }
}
