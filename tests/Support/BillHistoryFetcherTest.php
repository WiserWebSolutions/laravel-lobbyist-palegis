<?php

namespace WiserWebSolutions\LaravelPalegis\Tests\Support;

use Illuminate\Support\Facades\Http;
use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;
use WiserWebSolutions\LaravelPalegis\Support\BillHistoryFetcher;
use WiserWebSolutions\LaravelPalegis\Tests\TestCase;

class BillHistoryFetcherTest extends TestCase
{
    public function test_fetch_stream_yields_each_bill_and_never_returns_a_plain_array(): void
    {
        $this->fakeBillHistory();

        $bills = (new BillHistoryFetcher)->fetchStream('2025_0');

        $this->assertInstanceOf(\Generator::class, $bills);

        $ids = [];
        foreach ($bills as $bill) {
            $ids[] = $bill['id'];
        }

        $this->assertSame(['20250HB0017', '20250SB0100'], $ids);
    }

    public function test_fetch_stream_return_value_carries_export_date_and_total(): void
    {
        $this->fakeBillHistory();

        $bills = (new BillHistoryFetcher)->fetchStream('2025_0');
        iterator_to_array($bills, false);

        $meta = $bills->getReturn();

        $this->assertSame('July 9, 2026 7:30:08 PM EDT', $meta['export_date']);
        $this->assertSame(2, $meta['total']);
    }

    public function test_fetch_materializes_the_full_array_in_one_call(): void
    {
        $this->fakeBillHistory();

        $result = (new BillHistoryFetcher)->fetch('2025_0');

        $this->assertSame(2, $result['total']);
        $this->assertSame('July 9, 2026 7:30:08 PM EDT', $result['export_date']);
        $this->assertCount(2, $result['bills']);
        $this->assertSame('20250HB0017', $result['bills'][0]['id']);
    }

    public function test_fetch_stream_cleans_up_its_temp_files_after_full_consumption(): void
    {
        $this->fakeBillHistory();

        $before = glob(sys_get_temp_dir().'/palegis_bh_*');

        iterator_to_array((new BillHistoryFetcher)->fetchStream('2025_0'), false);

        $after = glob(sys_get_temp_dir().'/palegis_bh_*');

        $this->assertSame($before, $after);
    }

    public function test_no_xml_entry_in_the_archive_throws(): void
    {
        $zip = new \ZipArchive;
        $tmp = tempnam(sys_get_temp_dir(), 'palegis_test_zip_');
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('readme.txt', 'not xml');
        $zip->close();
        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        Http::fake([
            'www.palegis.us/data/file*' => Http::response($bytes, 200),
        ]);

        $this->expectException(PalegisException::class);
        $this->expectExceptionMessageMatches('/No XML entry found/');

        iterator_to_array((new BillHistoryFetcher)->fetchStream('2025_0'), false);
    }

    public function test_malformed_xml_throws(): void
    {
        $bytes = $this->zipString('bad.xml', 'this is not xml at all <<<');

        Http::fake([
            'www.palegis.us/data/file*' => Http::response($bytes, 200),
        ]);

        $this->expectException(PalegisException::class);
        $this->expectExceptionMessageMatches('/Invalid XML/');

        iterator_to_array((new BillHistoryFetcher)->fetchStream('2025_0'), false);
    }
}
