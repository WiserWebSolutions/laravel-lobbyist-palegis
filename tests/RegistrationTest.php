<?php

namespace WiserWebSolutions\LaravelPalegis\Tests;

use WiserWebSolutions\LaravelPalegis\LaravelPalegis;
use WiserWebSolutions\LaravelPalegis\PalegisDriver;
use WiserWebSolutions\Lobbyist\Contracts\Capability;
use WiserWebSolutions\Lobbyist\Facades\Lobbyist;
use WiserWebSolutions\Lobbyist\Testing\AssertsDriverContract;

class RegistrationTest extends TestCase
{
    use AssertsDriverContract;

    public function test_state_pa_resolves_the_palegis_driver(): void
    {
        $driver = Lobbyist::state('PA');

        $this->assertInstanceOf(PalegisDriver::class, $driver);
        $this->assertSame('PA', $driver->stateContext());
    }

    public function test_driver_honours_contract(): void
    {
        $driver = new PalegisDriver(new LaravelPalegis);

        $this->assertDriverContract($driver);

        // Bills come from the Bill History export (list + lookup); votes and
        // members are browse-only RSS feeds with no lookup.
        $this->assertTrue($driver->supports(Capability::ListBills));
        $this->assertTrue($driver->supports(Capability::GetBill));
        $this->assertTrue($driver->supports(Capability::ListVotes));
        $this->assertTrue($driver->supports(Capability::ListRepresentatives));
        $this->assertTrue($driver->supports(Capability::ListSessions));
        $this->assertFalse($driver->supports(Capability::GetVote));
        $this->assertFalse($driver->supports(Capability::GetRepresentative));

        $this->assertUnsupportedLookupThrows($driver, 'vote');
        $this->assertUnsupportedLookupThrows($driver, 'representative');
    }
}
