<?php

namespace App\Providers;

use Anthropic\Client;
use App\Ai\AnthropicStructuredModel;
use App\Ai\Contracts\StructuredModel;
use App\Ai\UsageCost;
use App\Http\Requests\Api\Auth\SendCodeRequest;
use App\Models\Staff;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Itp\Core\ItpCoreServiceProvider;
use Lightwalker\Sync\SyncServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services. The Anthropic client never retries on its own: the
     * generation jobs' tries and backoff are the only retries.
     */
    public function register(): void
    {
        $this->app->singleton(Client::class, fn () => new Client(
            apiKey: config('services.anthropic.key'),
            requestOptions: ['maxRetries' => 0],
        ));
        $this->app->singleton(UsageCost::class, fn () => new UsageCost(config('itp.ai.prices')));
        $this->app->bind(StructuredModel::class, AnthropicStructuredModel::class);
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
        $this->configureQueues();
        $this->configureStudioGates();
    }

    /**
     * The pipeline's `generation` queue runs on its own connection, whose retry_after outlasts
     * the generation jobs' timeout without slowing retries on `default`.
     */
    protected function configureQueues(): void
    {
        Queue::forward('generation', connection: config('itp.generation.queue_connection'));
    }

    /**
     * Prompt editing and costs are owner-only, like pipeline config (platform.md §9). Editors
     * still inspect generations.
     */
    protected function configureStudioGates(): void
    {
        Gate::define('managePrompts', fn (Staff $staff): bool => $staff->isOwner());
        Gate::define('viewCosts', fn (Staff $staff): bool => $staff->isOwner());
    }

    /**
     * Sign-in limits, per email and per IP. The IP limits are loose, because a mobile carrier
     * can put many users behind one address. Wrong guesses are also capped per code.
     *
     * Starting a generation is capped per user (`itp.generation.max_per_hour`): each one costs
     * real money in AI calls.
     *
     * The public content pull is capped per IP (`itp.content.requests_per_minute`), loosely: every
     * guest phone pulls on load, and many can share one carrier address.
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

        RateLimiter::for('generations', fn (Request $request) => Limit::perHour((int) config('itp.generation.max_per_hour'))
            ->by('generations:user:'.$request->user()?->getAuthIdentifier()));

        RateLimiter::for('content', fn (Request $request) => Limit::perMinute((int) config('itp.content.requests_per_minute'))
            ->by('content:ip:'.$request->ip()));
    }
}
