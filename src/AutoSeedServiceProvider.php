<?php

namespace AutoSeed;

use AutoSeed\Console\AutoSeedCommand;
use Illuminate\Support\ServiceProvider;

class AutoSeedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/autoseed.php', 'autoseed');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/autoseed.php' => config_path('autoseed.php'),
            ], 'autoseed-config');

            $this->commands([AutoSeedCommand::class]);
        }
    }
}
