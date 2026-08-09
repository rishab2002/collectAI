<?php

use App\Http\Controllers\Admin\CaptureController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\SubmissionReviewController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// No registration route by design — the only privileged account is created by
// AdminUserSeeder. See LoginController.
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('capture', [CaptureController::class, 'show'])->name('capture');
        Route::post('capture', [CaptureController::class, 'store'])->name('capture.store');

        // scopeBindings so {proposal} resolves through the submission's own
        // proposals rather than globally — a proposal id from another
        // submission 404s instead of being ruled on from the wrong page.
        Route::scopeBindings()->group(function () {
            Route::get('submissions/{submission}', [SubmissionReviewController::class, 'show'])
                ->name('submissions.show');

            Route::post('submissions/{submission}/proposals/{proposal}/approve', [SubmissionReviewController::class, 'approve'])
                ->name('submissions.proposals.approve');

            Route::post('submissions/{submission}/proposals/{proposal}/reject', [SubmissionReviewController::class, 'reject'])
                ->name('submissions.proposals.reject');
        });
    });
