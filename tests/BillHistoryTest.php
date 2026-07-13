<?php

namespace WiserWebSolutions\LaravelPalegis\Tests;

use Illuminate\Support\Facades\Cache;
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

        // bills() returns lightweight summaries — sponsors/full action
        // history aren't retained on every item in a large listing.
        $this->assertArrayNotHasKey('raw', $hb->meta);
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

    public function test_driver_bill_lookup_still_includes_full_raw_detail(): void
    {
        $this->fakeBillHistory();

        $hb = $this->driver()->setStateContext('PA')->bill('HB17');

        // Unlike bills(), a single bill() lookup can afford to keep the
        // full sponsors/printer/action history — only one record at a time.
        $this->assertArrayHasKey('raw', $hb->meta);
        $this->assertCount(2, $hb->meta['raw']['sponsors']);
        $this->assertCount(2, $hb->meta['raw']['actions']);
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

    private function enableCache(): void
    {
        $this->app['config']->set('palegis.cache.enabled', true);
        $this->app['config']->set('palegis.cache.store', 'array');
    }

    public function test_get_bill_history_uses_cache_on_second_call_without_a_second_http_request(): void
    {
        $this->enableCache();
        $this->fakeBillHistory();

        $first = (new LaravelPalegis)->getBillHistory('2025_0');
        $second = (new LaravelPalegis)->getBillHistory('2025_0');

        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    public function test_bill_history_is_cached_per_bill_and_by_index_not_as_one_entry(): void
    {
        $this->enableCache();
        $this->fakeBillHistory();

        (new LaravelPalegis)->getBillHistory('2025_0');

        $store = Cache::store('array');
        $this->assertTrue($store->has('palegis:bill-history:2025_0:index'));
        $this->assertTrue($store->has('palegis:bill-history:2025_0:bill:20250HB0017'));
        $this->assertTrue($store->has('palegis:bill-history:2025_0:bill:20250SB0100'));
    }

    public function test_driver_bill_lookup_hits_cache_without_a_second_http_request(): void
    {
        $this->enableCache();
        $this->fakeBillHistory();
        $driver = $this->driver()->setStateContext('PA');

        $driver->bills();

        $this->assertSame('HB17', $driver->bill('HB17')->number);
        $this->assertSame('HB17', $driver->bill('hb0017')->number);
        $this->assertSame('HB17', $driver->bill('20250HB0017')->number);
        $this->assertSame(Chamber::Senate, $driver->bill('SB100')->chamber);

        Http::assertSentCount(1);
    }

    public function test_stale_or_evicted_bill_cache_forces_a_resync(): void
    {
        $this->enableCache();
        $this->fakeBillHistory();

        (new LaravelPalegis)->getBillHistory('2025_0');
        Cache::store('array')->forget('palegis:bill-history:2025_0:bill:20250SB0100');

        $history = (new LaravelPalegis)->getBillHistory('2025_0');

        $this->assertCount(2, $history['bills']);
        Http::assertSentCount(2);
    }

    public function test_driver_bills_resyncs_when_a_bill_is_evicted_mid_stream(): void
    {
        $this->enableCache();
        $this->fakeBillHistory();
        $driver = $this->driver()->setStateContext('PA');

        $driver->bills();
        Cache::store('array')->forget('palegis:bill-history:2025_0:bill:20250SB0100');

        $bills = $driver->bills();

        $this->assertCount(2, $bills);
        Http::assertSentCount(2);
    }

}
