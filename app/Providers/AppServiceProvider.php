<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Itp\Core\ItpCoreServiceProvider;
use Lightwalker\Sync\SyncServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom([
            ItpCoreServiceProvider::migrationsPath(),
            SyncServiceProvider::serverMigrationsPath(),
        ]);
    }
}
