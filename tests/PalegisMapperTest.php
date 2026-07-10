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
}
