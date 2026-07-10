<?php

namespace WiserWebSolutions\LaravelPalegis\Tests;

use WiserWebSolutions\LaravelPalegis\Support\PalegisMapper;
use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Enums\StateEnum;

class PalegisMapperTest extends TestCase
{
    public function test_maps_vote_without_tallies(): void
    {
        $vote = PalegisMapper::vote([
            'title' => 'House Vote on HB100',
            'link' => 'https://www.palegis.us/vote/1',
            'guid' => 'v1',
        ], Chamber::House);

        $this->assertSame('v1', $vote->id);
        $this->assertSame(Chamber::House, $vote->chamber);
        $this->assertNull($vote->yea);
    }

    public function test_maps_legislator(): void
    {
        $legislator = PalegisMapper::legislator([
            'title' => 'Rep. Jane Doe',
            'link' => 'https://www.palegis.us/member/1',
            'guid' => 'm1',
        ], Chamber::House);

        $this->assertSame('Rep. Jane Doe', $legislator->name);
        $this->assertSame(Chamber::House, $legislator->chamber);
        $this->assertSame(StateEnum::PA, $legislator->state);
    }

    public function test_current_session_is_pennsylvania(): void
    {
        $session = PalegisMapper::currentSession();

        $this->assertSame(StateEnum::PA, $session->state);
    }

    private function billRecord(): array
    {
        return [
            'id' => '20250HB0017',
            'designator' => 'HB17',
            'short_title' => 'Cursive handwriting',
            'body' => 'H',
            'sponsors' => [
                ['name' => 'WATRO', 'party' => 'R', 'body' => 'H', 'district' => '116', 'sequence' => '01'],
            ],
            'printers_numbers' => [
                ['sequence' => '01', 'number' => '0002', 'pdf_url' => 'https://example.test/HB0017/PN0002'],
            ],
            'actions' => [
                ['sequence' => '01', 'full_action' => 'Referred to EDUCATION', 'date' => '01/08/25'],
                ['sequence' => '02', 'full_action' => 'Reported as committed', 'date' => '03/12/25'],
            ],
        ];
    }

    public function test_bill_from_history_includes_the_full_raw_record(): void
    {
        $bill = PalegisMapper::billFromHistory($this->billRecord());

        $this->assertSame('HB17', $bill->number);
        $this->assertSame('Reported as committed', $bill->lastAction);
        $this->assertArrayHasKey('raw', $bill->meta);
        $this->assertCount(1, $bill->meta['raw']['sponsors']);
        $this->assertCount(2, $bill->meta['raw']['actions']);
    }

    public function test_bill_summary_from_history_omits_the_raw_record(): void
    {
        $bill = PalegisMapper::billSummaryFromHistory($this->billRecord());

        $this->assertSame('HB17', $bill->number);
        $this->assertSame('Reported as committed', $bill->lastAction);
        $this->assertStringContainsString('HB0017/PN0002', $bill->url);
        $this->assertArrayNotHasKey('raw', $bill->meta);
    }
}
