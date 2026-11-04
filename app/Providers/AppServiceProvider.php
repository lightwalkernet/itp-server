<?php

namespace App\Providers;

use App\Http\Requests\Api\Auth\SendCodeRequest;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        $this->configureRateLimiting();
    }

    /**
     * Sign-in limits, per email and per IP. The IP limits are loose, because a mobile carrier
     * can put many users behind one address. Wrong guesses are also capped per code.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('send-code', function (Request $request) {
            $email = SendCodeRequest::normalizeEmail($request->input('email'));

            return [
                Limit::perMinute(1)->by('send-code:email-minute:'.$email),
                Limit::perHour(10)->by('send-code:email-hour:'.$email),
                Limit::perHour(60)->by('send-code:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('verify-code', function (Request $request) {
            $email = SendCodeRequest::normalizeEmail($request->input('email'));

            return [
                Limit::perMinute(10)->by('verify-code:email:'.$email),
                Limit::perMinute(30)->by('verify-code:ip:'.$request->ip()),
            ];
        });
    }
}
