<?php

namespace WiserWebSolutions\LaravelPalegis\Tests;

use WiserWebSolutions\LaravelPalegis\Support\PalegisMapper;
use WiserWebSolutions\Lobbyist\Data\BillText;
use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Enums\StateEnum;
use WiserWebSolutions\Lobbyist\Exceptions\LobbyistException;

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
                ['sequence' => '01', 'number' => '0002', 'pdf_url' => 'https://www.palegis.us/legislation/bills/text/PDF/2025/0/HB0017/PN0002'],
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

    public function test_bill_text_exposes_the_html_and_pdf_links(): void
    {
        $bill = PalegisMapper::billFromHistory($this->billRecord());

        $text = $bill->text();

        $this->assertInstanceOf(BillText::class, $text);
        $this->assertSame('https://www.palegis.us/legislation/bills/text/HTM/2025/0/HB0017/PN0002', $text->toHTML());
        $this->assertSame('https://www.palegis.us/legislation/bills/text/PDF/2025/0/HB0017/PN0002', $text->toPDF());
    }

    public function test_bill_text_to_string_throws_without_a_driver_fetch(): void
    {
        // Bill::text() is a pure read of already-mapped data — it never
        // performs I/O on its own. Fetching the literal text requires
        // PalegisDriver::billText(), which is what actually calls the HTTP
        // client and strips the HTML down to plain text.
        $bill = PalegisMapper::billFromHistory($this->billRecord());

        $this->expectException(LobbyistException::class);
        $this->expectExceptionMessageMatches('/does not have bill text support \(toString\(\)\)/');

        $bill->text()->toString();
    }

    public function test_bill_text_falls_back_to_unsupported_without_a_printers_number(): void
    {
        $record = $this->billRecord();
        $record['printers_numbers'] = [];

        $bill = PalegisMapper::billFromHistory($record);

        $this->assertCount(0, $bill->texts());
        $this->expectException(LobbyistException::class);

        $bill->text()->toHTML();
    }

    public function test_bill_text_history_maps_each_printers_number_with_no_content(): void
    {
        $record = $this->billRecord();
        $record['printers_numbers'] = [
            ['sequence' => '01', 'number' => '0002', 'pdf_url' => 'https://www.palegis.us/legislation/bills/text/PDF/2025/0/HB0017/PN0002'],
            ['sequence' => '02', 'number' => '0101', 'pdf_url' => 'https://www.palegis.us/legislation/bills/text/PDF/2025/0/HB0017/PN0101'],
        ];
        $record['actions'] = [
            ['full_action' => 'Referred to EDUCATION', 'date' => '01/08/25', 'printers_number' => '0002'],
            ['full_action' => 'Amended on third reading', 'date' => '03/12/25', 'printers_number' => '0101'],
        ];

        $history = PalegisMapper::billTextHistory($record);

        $this->assertCount(2, $history);
        $this->assertContainsOnlyInstancesOf(BillText::class, $history);
        $this->assertSame('https://www.palegis.us/legislation/bills/text/HTM/2025/0/HB0017/PN0101', $history->last()->url);
        $this->assertSame('text/html', $history->last()->mime);
        $this->assertSame('01/08/25', $history->first()->date?->format('m/d/y'));
        $this->assertNull($history->first()->content);
        $this->assertSame('20250HB0017', $history->first()->billId);
    }

    public function test_bill_text_history_leaves_date_null_when_no_action_references_the_printers_number(): void
    {
        $record = $this->billRecord();

        $history = PalegisMapper::billTextHistory($record);

        $this->assertCount(1, $history);
        $this->assertNull($history->first()->date);
    }
}
