<?php

namespace WiserWebSolutions\LaravelPalegis\Tests\Support;

use Illuminate\Support\Facades\Cache;
use WiserWebSolutions\LaravelPalegis\Support\BillHistoryCache;
use WiserWebSolutions\LaravelPalegis\Tests\TestCase;

class BillHistoryCacheTest extends TestCase
{
    private function cache(): BillHistoryCache
    {
        return new BillHistoryCache(Cache::store('array'), 3600);
    }

    /** @return array{export_date: string, total: int, session: string, bills: array<int, array>} */
    private function parsed(): array
    {
        return [
            'export_date' => 'July 9, 2026 7:30:08 PM EDT',
            'total' => 2,
            'session' => '2025_0',
            'bills' => [
                ['id' => '20250HB0017', 'designator' => 'HB17', 'short_title' => 'Cursive handwriting'],
                ['id' => '20250SB0100', 'designator' => 'SB100', 'short_title' => 'Appropriations'],
            ],
        ];
    }

    public function test_has_index_is_false_before_any_put(): void
    {
        $this->assertFalse($this->cache()->hasIndex('2025_0'));
    }

    public function test_put_then_all_reassembles_the_full_session_payload_in_order(): void
    {
        $cache = $this->cache();
        $cache->put('2025_0', $this->parsed());

        $this->assertTrue($cache->hasIndex('2025_0'));

        $result = $cache->all('2025_0');

        $this->assertSame('July 9, 2026 7:30:08 PM EDT', $result['export_date']);
        $this->assertSame(2, $result['total']);
        $this->assertSame('2025_0', $result['session']);
        $this->assertSame('20250HB0017', $result['bills'][0]['id']);
        $this->assertSame('20250SB0100', $result['bills'][1]['id']);
    }

    public function test_find_locates_a_bill_by_full_id(): void
    {
        $cache = $this->cache();
        $cache->put('2025_0', $this->parsed());

        $record = $cache->find('2025_0', '20250HB0017');

        $this->assertSame('Cursive handwriting', $record['short_title']);
    }

    public function test_find_locates_a_bill_by_normalized_designator_case_and_padding_insensitive(): void
    {
        $cache = $this->cache();
        $cache->put('2025_0', $this->parsed());

        $this->assertSame('Cursive handwriting', $cache->find('2025_0', 'hb0017')['short_title']);
        $this->assertSame('Appropriations', $cache->find('2025_0', 'sb100')['short_title']);
    }

    public function test_find_returns_null_for_an_unrecognized_identifier(): void
    {
        $cache = $this->cache();
        $cache->put('2025_0', $this->parsed());

        $this->assertNull($cache->find('2025_0', 'HB9999'));
    }

    public function test_all_returns_null_when_a_per_bill_entry_is_missing_even_though_the_index_exists(): void
    {
        $cache = $this->cache();
        $cache->put('2025_0', $this->parsed());

        Cache::store('array')->forget('palegis:bill-history:2025_0:bill:20250SB0100');

        $this->assertTrue($cache->hasIndex('2025_0'));
        $this->assertNull($cache->all('2025_0'));
    }
}
