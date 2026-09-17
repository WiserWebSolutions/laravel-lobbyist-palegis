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

    public function test_the_driver_is_also_registered_under_a_stable_name(): void
    {
        // A second, distinct registration -- so an application asking for
        // this driver explicitly by name (e.g. as its bills aggregator) is
        // not making the exact same manager lookup as state('PA'), even
        // though both construct the same driver class. See
        // LaravelPalegisServiceProvider::boot()'s docblock for why that
        // distinction matters to a caller comparing the two.
        $driver = Lobbyist::driver('palegis');

        $this->assertInstanceOf(PalegisDriver::class, $driver);
        $this->assertNotSame(Lobbyist::driver('pa'), $driver);
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
        $this->assertTrue($driver->supports(Capability::ListChamberSessionDays));
        $this->assertFalse($driver->supports(Capability::GetVote));
        $this->assertFalse($driver->supports(Capability::GetRepresentative));

        $this->assertUnsupportedLookupThrows($driver, 'vote');
        $this->assertUnsupportedLookupThrows($driver, 'representative');
    }
}
