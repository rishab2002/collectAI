<?php

namespace App\Providers;

use Anthropic\Client;
use Illuminate\Support\ServiceProvider;

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
        //
    }
}
