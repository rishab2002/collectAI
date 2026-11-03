<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The daily price run: refreshes the published cars whose prices are oldest,
// within PRICE_DAILY_BUDGET eBay calls, so every car updates about monthly.
// In production the `scheduler` container runs this (docker-compose.prod.yml).
Schedule::command('catalog:update-prices')
    ->dailyAt('03:17')
    ->withoutOverlapping()
    ->onOneServer();

// The catalog file the local recognizer matches against. Nightly, so cars
// published today are recognisable tomorrow; the recognizer reloads it by
// itself when the file changes.
Schedule::command('catalog:export-recognition-index')
    ->dailyAt('04:07')
    ->withoutOverlapping()
    ->onOneServer();

// Pictures from scans whose owner didn't agree to keep them.
Schedule::command('catalog:prune-scans')
    ->hourly()
    ->onOneServer();
