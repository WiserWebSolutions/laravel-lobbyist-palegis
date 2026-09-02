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

        // Registered under the package's own name rather than the state
        // abbreviation, lazily and regardless of provider boot order.
        //
        // Naming it 'pa' would make Lobbyist::state('PA') resolve here, since
        // the manager prefers a driver registered under the state. That is the
        // right behaviour for a driver that IS the state's bill source, and the
        // wrong behaviour for this one: these RSS feeds carry rosters, photos
        // and schedules, not datasets or change hashes. Installing this package
        // alongside a bill driver would silently replace it and take the
        // dataset import, change detection and text fetching down with it.
        //
        // An application that genuinely wants this as its primary PA source can
        // still say so with lobbyist.drivers.default, or ask for it by name.
        $this->app->resolving('lobbyist', function (LobbyistManager $manager) {
            $manager->extend('palegis', fn ($app) => new PalegisDriver(
                $app->make(LaravelPalegis::class)
            ));
        });
    }
}
