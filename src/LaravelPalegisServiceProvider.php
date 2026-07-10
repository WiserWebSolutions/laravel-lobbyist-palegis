<?php

namespace WiserWebSolutions\LaravelPalegis;

use Illuminate\Support\ServiceProvider;
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
        }

        // Register the PA driver with the core Lobbyist manager, lazily and
        // regardless of provider boot order.
        $this->app->resolving('lobbyist', function (LobbyistManager $manager) {
            $manager->extend('pa', fn () => new PalegisDriver(
                app(LaravelPalegis::class)
            ));
        });
    }
}
