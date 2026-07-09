<?php

namespace WiserWebSolutions\LaravelPalegis\Tests;

use WiserWebSolutions\LaravelPalegis\Support\PalegisMapper;
use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Enums\StateEnum;

class PalegisMapperTest extends TestCase
{
    public function test_maps_bill_and_extracts_number_from_title(): void
    {
        $bill = PalegisMapper::bill([
            'title' => 'HB 1234 - An Act concerning something',
            'link' => 'https://www.palegis.us/bill/HB1234',
            'description' => 'Referred to committee',
            'pub_date' => 'Mon, 01 May 2023 12:00:00 -0400',
            'guid' => 'hb1234',
        ], Chamber::House);

        $this->assertSame('hb1234', $bill->id);
        $this->assertSame('HB1234', $bill->number);
        $this->assertSame(StateEnum::PA, $bill->state);
        $this->assertSame(Chamber::House, $bill->chamber);
    }

    public function test_maps_bill_number_from_link_when_absent_from_title(): void
    {
        $bill = PalegisMapper::bill([
            'title' => 'An Act concerning something',
            'link' => 'https://www.palegis.us/legislation/bills/SB0042',
        ], Chamber::Senate);

        $this->assertSame('SB42', $bill->number);
    }

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
}
