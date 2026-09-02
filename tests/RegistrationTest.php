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

    public function test_the_driver_is_registered_under_the_state_abbreviation(): void
    {
        // Which is what makes Lobbyist::state('PA') resolve here.
        $driver = Lobbyist::driver('pa');

        $this->assertInstanceOf(PalegisDriver::class, $driver);
        $this->assertInstanceOf(PalegisDriver::class, Lobbyist::state('PA'));
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
        $this->assertTrue($driver->supports(Capability::ListLegislators));
        $this->assertTrue($driver->supports(Capability::ListSessions));
        $this->assertTrue($driver->supports(Capability::GetBillText));
        $this->assertTrue($driver->supports(Capability::ListBillTextHistory));

        // The reason to install this at all: the assignments and schedules no
        // aggregator publishes.
        $this->assertTrue($driver->supports(Capability::ListCommitteeAssignments));
        $this->assertTrue($driver->supports(Capability::ListCommitteeMeetings));
        $this->assertFalse($driver->supports(Capability::GetVote));
        $this->assertFalse($driver->supports(Capability::GetRepresentative));

        $this->assertUnsupportedLookupThrows($driver, 'vote');
        $this->assertUnsupportedLookupThrows($driver, 'representative');
    }
}
