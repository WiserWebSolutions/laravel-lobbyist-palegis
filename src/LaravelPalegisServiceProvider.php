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

        // Registered under both the state abbreviation and a stable driver
        // name, lazily and regardless of provider boot order.
        //
        // The state-abbreviation name is the mechanism for state(): LobbyistManager::state()
        // prefers a driver registered under the lowercased abbreviation over
        // the configured default, so installing this package makes it the PA
        // driver. That is deliberate -- it is the state's own source, and an
        // application that installs it is asking for it.
        //
        // The 'palegis' name exists so an application can ask for this driver
        // explicitly by name (e.g. as its bills aggregator, via
        // App\Modules\PolicyPulse\Support\BillSource) without that being the
        // same string state() itself resolves through. Keeping the two names
        // distinct -- even though both construct the same driver class --
        // matters to any caller that compares "the driver I asked for by
        // name" against "whatever state() resolved" to decide whether they're
        // redundant (see App\Modules\PolicyPulse\Sync\LegislatorImporter's own
        // such guard): were 'pa' the only name, asking for the aggregator by
        // name and asking via state() would be the exact same manager lookup,
        // not merely the same resulting class.
        //
        // What it is not is a superset. This driver now also supports
        // DatasetProvider/DatasetLookup/BillChangeProvider (see PalegisDriver's
        // class doc), but its dataset votes() is always empty -- roll-call
        // votes live only on scraped, per-roll-call HTML pages, not in the
        // Bill History export. An application that needs those must ask its
        // aggregator for them by name rather than through state(), which is
        // what supports(Capability::…) is for.
        $this->app->resolving('lobbyist', function (LobbyistManager $manager) {
            $factory = fn ($app) => new PalegisDriver($app->make(LaravelPalegis::class));

            $manager->extend('pa', $factory);
            $manager->extend('palegis', $factory);
        });
    }
}
