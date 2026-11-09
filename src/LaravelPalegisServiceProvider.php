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

        // The provider name is canonical; the PA alias preserves existing consumers.
        $this->app->resolving('lobbyist', function (LobbyistManager $manager) {
            $manager->extend('palegis.us', fn ($app) => new PalegisDriver(
                $app->make(LaravelPalegis::class)
            ));
            $manager->alias('pa', 'palegis.us');
        });
    }
}
