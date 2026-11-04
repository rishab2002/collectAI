<?php

use App\Http\Controllers\RecognitionIndexController;
use Illuminate\Support\Facades\Route;

// Machine-to-machine routes, registered outside the `web` group
// (bootstrap/app.php): no session, no cookies, no CSRF. A recognizer polling
// every few minutes would otherwise leave a session row behind each time.

// For a recognizer on another machine (Railway): token-guarded and 404
// without the token. See RecognitionIndexController.
Route::get('internal/recognition-index', RecognitionIndexController::class)
    ->name('recognition.index');
