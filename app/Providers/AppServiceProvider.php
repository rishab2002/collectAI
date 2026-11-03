<?php

namespace App\Providers;

use Anthropic\Client;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bound rather than constructed inside the agents so a test can swap the
        // whole client — the SDK takes a PSR-18 transporter, which is the seam
        // OcrAgentTest and MetadataAgentTest drive instead of reaching the
        // network.
        //
        // One client for every agent: they are calls to the same account, and
        // the per-agent settings that do differ (model, effort, token budget)
        // are request parameters rather than client state.
        //
        // The key is read through config, not env(), so it still resolves once
        // config is cached. An unset key isn't an error here — it surfaces as a
        // 401 on the first real call, which the commands report with the fix.
        //
        // Passed explicitly rather than left to the SDK's own env lookup: that
        // lookup reads ANTHROPIC_API_KEY, and config/catalog.php deliberately
        // doesn't use that name — see the comment there.
        $this->app->singleton(
            Client::class,
            fn (): Client => new Client(apiKey: config('catalog.anthropic.api_key')),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every password rule in the app goes through this: at least 12
        // characters with letters and numbers, and — in production, where the
        // network is real — not one that appears in a known breach (checked
        // against Have I Been Pwned by k-anonymity; only a hash prefix leaves).
        Password::defaults(function (): Password {
            $rule = Password::min(12)->letters()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // Account creation is the cheapest thing to abuse, so it is the
        // tightest: a handful per address per hour.
        RateLimiter::for('register', fn (Request $request): Limit => Limit::perHour(5)->by($request->ip()));

        // Collection and account writes: generous for a person, a wall for a
        // script.
        RateLimiter::for('account', fn (Request $request): Limit => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
    }
}
