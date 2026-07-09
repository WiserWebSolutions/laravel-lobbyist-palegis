<?php

namespace WiserWebSolutions\LaravelPalegis\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use WiserWebSolutions\LaravelPalegis\LaravelPalegisServiceProvider;
use WiserWebSolutions\LaravelPalegis\PalegisDriver;
use WiserWebSolutions\Lobbyist\Facades\Lobbyist;
use WiserWebSolutions\Lobbyist\Legiscan\LegiscanDriver;
use WiserWebSolutions\Lobbyist\Legiscan\LegiscanServiceProvider;
use WiserWebSolutions\Lobbyist\LobbyistServiceProvider;

/**
 * Exercises all three packages installed together: core routing, the PA state
 * driver, and the LegiScan default-driver fallback.
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

    public function test_pa_routes_to_the_palegis_driver(): void
    {
        $driver = Lobbyist::state('PA');

        $this->assertInstanceOf(PalegisDriver::class, $driver);
        $this->assertSame('PA', $driver->stateContext());
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
