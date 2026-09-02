<?php

namespace WiserWebSolutions\LaravelPalegis;

use Illuminate\Support\ServiceProvider;
use WiserWebSolutions\LaravelPalegis\Console\Commands\SyncBillHistoryCommand;
use WiserWebSolutions\Lobbyist\LobbyistManager;

class LaravelPalegisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/palegis.php', 'palegis');

        $this->app->singleton(LaravelPalegis::class, fn () => new LaravelPalegis);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/palegis.php' => config_path('palegis.php'),
            ], 'palegis-config');

            $this->commands([
                SyncBillHistoryCommand::class,
            ]);
        }

        // Registered under the state abbreviation, lazily and regardless of
        // provider boot order.
        //
        // The name is the mechanism: LobbyistManager::state() prefers a driver
        // registered under the lowercased abbreviation over the configured
        // default, so installing this package makes it the PA driver. That is
        // deliberate -- it is the state's own source, and an application that
        // installs it is asking for it.
        //
        // What it is not is a superset. These feeds carry rosters, photos and
        // schedules; they carry no datasets, change hashes or bill text. An
        // application that needs those alongside this must ask its aggregator
        // for them by name rather than through state(), which is what
        // supports(Capability::…) is for.
        $this->app->resolving('lobbyist', function (LobbyistManager $manager) {
            $manager->extend('pa', fn ($app) => new PalegisDriver(
                $app->make(LaravelPalegis::class)
            ));
        });
    }
}
