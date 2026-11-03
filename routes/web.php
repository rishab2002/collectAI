<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\CollectionController;
use App\Http\Controllers\Account\ScanController;
use App\Http\Controllers\Admin\CaptureController;
use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ProposalReviewController;
use App\Http\Controllers\Admin\SubmissionReviewController;
use App\Http\Controllers\Admin\VariationPhotoController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CatalogBrowseController;
use Illuminate\Support\Facades\Route;

// The public catalog: brand → main line → sub-series → car. Live records
// only; an admin can preview the pending backlog. See CatalogBrowseController.
Route::get('/', [CatalogBrowseController::class, 'home'])->name('home');
Route::get('brands/{brand}', [CatalogBrowseController::class, 'brand'])->name('catalog.brand');
Route::get('lines/{line}', [CatalogBrowseController::class, 'line'])->name('catalog.line');
Route::get('cars/{casting}', [CatalogBrowseController::class, 'casting'])
    ->whereNumber('casting')
    ->name('catalog.casting');
Route::post('preview', [CatalogBrowseController::class, 'togglePreview'])
    ->middleware(['auth', 'admin'])
    ->name('catalog.preview');

// No registration route by design — the only privileged account is created by
// AdminUserSeeder. See LoginController.
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');
    Route::get('register', [RegisterController::class, 'show'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])
        ->middleware('throttle:register')
        ->name('register.store');
});

// A collector's own pages. Every lookup is scoped to the signed-in user in the
// controller, so another collector's ids 404.
Route::middleware(['auth', 'throttle:account'])->group(function () {
    Route::get('collection', [CollectionController::class, 'index'])->name('collection.index');
    Route::post('collection', [CollectionController::class, 'store'])->name('collection.store');
    Route::patch('collection/{item}', [CollectionController::class, 'update'])->whereNumber('item')->name('collection.update');
    Route::delete('collection/{item}', [CollectionController::class, 'destroy'])->whereNumber('item')->name('collection.destroy');
    Route::post('wishlist', [CollectionController::class, 'wish'])->name('wishlist.store');
    Route::delete('wishlist/{variation}', [CollectionController::class, 'unwish'])->whereNumber('variation')->name('wishlist.destroy');

    // Scan: photograph your cars, confirm what the local recognizer found.
    Route::get('scan', [ScanController::class, 'index'])->name('scan.index');
    Route::post('scan', [ScanController::class, 'store'])->middleware('throttle:scan')->name('scan.store');
    Route::get('scan/{scan}', [ScanController::class, 'show'])->whereNumber('scan')->name('scan.show');
    Route::delete('scan/{scan}', [ScanController::class, 'destroy'])->whereNumber('scan')->name('scan.destroy');
    Route::post('scan/{scan}/detections/{detection}/add', [ScanController::class, 'add'])->whereNumber(['scan', 'detection'])->name('scan.add');
    Route::post('scan/{scan}/detections/{detection}/reject', [ScanController::class, 'reject'])->whereNumber(['scan', 'detection'])->name('scan.reject');
    Route::get('scan/{scan}/detections/{detection}/crop', [ScanController::class, 'crop'])->whereNumber(['scan', 'detection'])->name('scan.crop');

    Route::get('account', [AccountController::class, 'show'])->name('account.show');
    Route::put('account/password', [AccountController::class, 'password'])->name('account.password');
    Route::delete('account', [AccountController::class, 'destroy'])->name('account.destroy');
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

        // The admin's own view of the catalog, every status included, and the
        // manual entry path: cars typed in by hand go live on the spot because
        // the admin is the reviewer. See ManualCatalogEntry.
        Route::get('catalog', [CatalogController::class, 'index'])->name('catalog.index');
        Route::get('castings/create', [CatalogController::class, 'create'])->name('castings.create');
        Route::post('castings', [CatalogController::class, 'store'])->name('castings.store');
        Route::get('castings/{casting}', [CatalogController::class, 'show'])->name('castings.show');
        Route::post('castings/{casting}/variations', [CatalogController::class, 'storeVariation'])
            ->name('castings.variations.store');

        Route::post('variations/{variation}/photos', [VariationPhotoController::class, 'store'])
            ->name('variations.photos.store');

        // Scoped, so a photo id belonging to another variation 404s rather than
        // being made primary or deleted from the wrong car's page.
        Route::scopeBindings()->group(function () {
            Route::post('variations/{variation}/photos/{photo}/primary', [VariationPhotoController::class, 'primary'])
                ->name('variations.photos.primary');
            Route::delete('variations/{variation}/photos/{photo}', [VariationPhotoController::class, 'destroy'])
                ->name('variations.photos.destroy');
        });

        // Not nested under a submission, because these proposals have none — the
        // importer's inferences are records the pipeline produced with no capture
        // behind them. Unscoped bindings are fine here for the same reason: there
        // is no parent to scope to, and the controller turns away a proposal that
        // does belong to a submission.
        Route::get('proposals', [ProposalReviewController::class, 'index'])->name('proposals.index');
        Route::post('proposals/{proposal}/approve', [ProposalReviewController::class, 'approve'])
            ->name('proposals.approve');
        Route::post('proposals/{proposal}/reject', [ProposalReviewController::class, 'reject'])
            ->name('proposals.reject');

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
