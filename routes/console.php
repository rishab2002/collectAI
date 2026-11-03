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
