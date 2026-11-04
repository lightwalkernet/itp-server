<?php

namespace App\Providers;

use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Only the gate decides, in every environment. Horizon's default also lets anyone in on
     * `local`, which would let an editor in during development.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn (Request $request): bool => $request->user('studio')?->can('viewHorizon') ?? false);
    }

    /**
     * Horizon, failed jobs and queue health are owner-only (platform.md §9).
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (Staff $staff): bool => $staff->isOwner());
    }
}
