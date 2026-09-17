<?php

namespace WiserWebSolutions\LaravelPalegis\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use WiserWebSolutions\LaravelPalegis\LaravelPalegisServiceProvider;
use WiserWebSolutions\LaravelPalegis\PalegisDriver;
use WiserWebSolutions\Lobbyist\Contracts\Capability;
use WiserWebSolutions\Lobbyist\Facades\Lobbyist;
use WiserWebSolutions\Lobbyist\Legiscan\LegiscanDriver;
use WiserWebSolutions\Lobbyist\Legiscan\LegiscanServiceProvider;
use WiserWebSolutions\Lobbyist\LobbyistServiceProvider;

/**
 * Exercises all three packages installed together.
 *
 * Installing this package makes it the PA driver, because it registers under
 * the state abbreviation and the manager prefers that over the configured
 * default. That is the intended contract: it is the state's own source.
 *
 * What the tests pin is the consequence -- it is not a superset of the
 * aggregator. It does support datasets, change hashes, and floor/committee
 * roll-call votes now (see PalegisDriver's class doc), but it still has no
 * per-identifier vote/representative lookup or bill-text-version lookup, so
 * an application that needs those has to ask for the aggregator by name
 * rather than through state().
 */
class IntegrationTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LobbyistServiceProvider::class,
            LegiscanServiceProvider::class,
            LaravelPalegisServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('palegis.cache.enabled', false);
        $app['config']->set('lobbyist-legiscan.endpoint.api_key', 'test-key');
        $app['config']->set('lobbyist-legiscan.endpoint.base_uri', 'https://api.legiscan.test/');
        $app['config']->set('lobbyist-legiscan.cache.enabled', false);
    }

    public function test_installing_this_package_makes_it_the_pa_driver(): void
    {
        $driver = Lobbyist::state('PA');

        // Registered under 'pa', which the manager prefers over the configured
        // default. Installing the state's own source is how an application
        // asks for it.
        $this->assertInstanceOf(PalegisDriver::class, $driver);
        $this->assertSame('PA', $driver->stateContext());

        // And it is the one that answers the questions the aggregator cannot.
        $this->assertTrue($driver->supports(Capability::ListCommitteeAssignments));
        $this->assertTrue($driver->supports(Capability::ListCommitteeMeetings));
    }

    public function test_it_is_not_a_superset_of_the_aggregator(): void
    {
        $driver = Lobbyist::state('PA');

        // It now answers datasets and change hashes from the Bill History
        // export -- but the export carries no per-identifier vote/rep lookup
        // or bill-text-version lookup, which remain aggregator-only.
        $this->assertTrue($driver->supports(Capability::GetDataset));
        $this->assertTrue($driver->supports(Capability::ListBillChanges));
        $this->assertFalse($driver->supports(Capability::GetBillTextVersion));
        $this->assertFalse($driver->supports(Capability::GetVote));
        $this->assertFalse($driver->supports(Capability::GetRepresentative));
    }

    public function test_the_aggregator_is_still_reachable_by_name(): void
    {
        // How that application gets the rest back. Overriding state('PA') took
        // nothing away from the manager.
        $driver = Lobbyist::driver('legiscan');

        $this->assertInstanceOf(LegiscanDriver::class, $driver);
        $this->assertTrue($driver->supports(Capability::GetDataset));
        $this->assertTrue($driver->supports(Capability::ListBillChanges));
        $this->assertTrue($driver->supports(Capability::GetBillTextVersion));
    }

    public function test_other_states_fall_back_to_the_legiscan_default(): void
    {
        $ca = Lobbyist::state('CA');
        $this->assertInstanceOf(LegiscanDriver::class, $ca);
        $this->assertSame('CA', $ca->stateContext());

        $tx = Lobbyist::state('TX');
        $this->assertInstanceOf(LegiscanDriver::class, $tx);
        $this->assertSame('TX', $tx->stateContext());
    }

    public function test_both_drivers_are_registered_on_the_manager(): void
    {
        $this->assertInstanceOf(PalegisDriver::class, Lobbyist::driver('pa'));
        $this->assertInstanceOf(LegiscanDriver::class, Lobbyist::driver('legiscan'));
    }
}
