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
 * The important case is the first one: installing this package must not change
 * where bill data comes from. It registers under its own name rather than the
 * state abbreviation for exactly that reason.
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

    public function test_installing_this_package_does_not_displace_the_bill_driver(): void
    {
        $driver = Lobbyist::state('PA');

        // Registering under 'pa' would win here, because the manager prefers a
        // driver named after the state. That silently replaced LegiScan and
        // took the dataset import, change detection and text fetching with it
        // -- these feeds carry rosters and schedules, not datasets.
        $this->assertInstanceOf(LegiscanDriver::class, $driver);
        $this->assertSame('PA', $driver->stateContext());

        $this->assertTrue($driver->supports(Capability::GetDataset));
        $this->assertTrue($driver->supports(Capability::ListBillChanges));
    }

    public function test_the_palegis_driver_is_available_by_name(): void
    {
        $driver = Lobbyist::driver('palegis');

        $this->assertInstanceOf(PalegisDriver::class, $driver);

        // And it is the one that answers the questions LegiScan cannot.
        $this->assertTrue($driver->supports(Capability::ListCommitteeAssignments));
        $this->assertTrue($driver->supports(Capability::ListCommitteeMeetings));
    }

    public function test_an_application_can_still_choose_it_as_the_default(): void
    {
        config(['lobbyist.drivers.default' => 'palegis']);

        // Opt-in rather than a side effect of installing the package.
        $this->assertInstanceOf(PalegisDriver::class, Lobbyist::state('PA'));
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
        $this->assertInstanceOf(PalegisDriver::class, Lobbyist::driver('palegis'));
        $this->assertInstanceOf(LegiscanDriver::class, Lobbyist::driver('legiscan'));
    }
}
