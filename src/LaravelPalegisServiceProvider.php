<?php

namespace WiserWebSolutions\LaravelPalegis;

use Illuminate\Support\ServiceProvider;
use WiserWebSolutions\LaravelPalegis\Console\Commands\SyncBillHistoryCommand;
use WiserWebSolutions\LaravelPalegis\Console\Commands\VerifyScrapersCommand;
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
                VerifyScrapersCommand::class,
            ]);
        }

        // Registered under the state abbreviation, lazily and regardless of
        // provider boot order.
        //
        // The name is the mechanism: LobbyistManager::state() prefers a driver
        // registered under the lowercased abbreviation over the configured
        // default, so installing this package makes it the PA driver. That is
        // deliberate -- it is the state's own source, and an application that
        // installs it is asking for it. An application that wants this driver
        // by name rather than through state() -- e.g. to configure it as its
        // bills aggregator -- asks for 'pa' directly; no second name needed.
        //
        // What it is not is a superset. This driver now also supports
        // DatasetProvider/DatasetLookup/BillChangeProvider (see PalegisDriver's
        // class doc), including floor and committee roll-call votes -- but
        // still no lookup by arbitrary identifier (GetVote, GetRepresentative,
        // GetBillTextVersion). An application that needs those must ask its
        // aggregator for them by name rather than through state(), which is
        // what supports(Capability::…) is for.
        $this->app->resolving('lobbyist', function (LobbyistManager $manager) {
            $manager->extend('pa', fn ($app) => new PalegisDriver(
                $app->make(LaravelPalegis::class)
            ));
        });
    }
}
