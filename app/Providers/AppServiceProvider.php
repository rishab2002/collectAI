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
        // Bound rather than constructed inside OcrAgent so a test can swap the
        // whole client — the SDK takes a PSR-18 transporter, which is the seam
        // OcrAgentTest drives instead of reaching the network.
        //
        // The key is read through config, not env(), so it still resolves once
        // config is cached. The SDK falls back to the ANTHROPIC_API_KEY
        // environment variable when passed null, which is why an unset key
        // isn't an error here — it surfaces as a 401 on the first real call.
        $this->app->singleton(
            Client::class,
            fn (): Client => new Client(apiKey: config('catalog.ocr.api_key')),
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
