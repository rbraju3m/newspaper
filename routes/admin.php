<?php

use App\Http\Controllers\Admin;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin panel
|--------------------------------------------------------------------------
|
| Everything here sits behind auth + the `staff` middleware, which 404s for
| readers. Per-action authorisation is handled by ArticlePolicy/UserPolicy and
| the CommentPolicy `moderate` ability inside the controllers.
|
*/

// `throttle:admin` is a backstop rather than a control: 120 a minute is well
// past what a busy editor does by hand, and well short of what a runaway
// script or a taken-over reporter account does.
Route::middleware(['auth', 'staff', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {

    Route::get('/', Admin\DashboardController::class)->name('dashboard');

    // ── Articles ─────────────────────────────────────────────────────────
    Route::controller(Admin\ArticleController::class)->prefix('articles')->name('articles.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/{article}/edit', 'edit')->name('edit');
        Route::put('/{article}', 'update')->name('update');
        Route::patch('/{article}/status', 'status')->name('status');
        // Irreversible and reaches every subscribed browser, so it is its own
        // action rather than a side effect of saving `is_breaking`.
        Route::post('/{article}/push', 'push')->name('push');
        Route::delete('/{article}', 'destroy')->name('destroy');
    });

    // ── Live blog entries ────────────────────────────────────────────────
    Route::controller(Admin\LiveEntryController::class)->group(function () {
        Route::get('/articles/{article}/live', 'index')->name('live.index');
        Route::post('/articles/{article}/live', 'store')->name('live.store');
        Route::put('/live/{entry}', 'update')->name('live.update');
        Route::delete('/live/{entry}', 'destroy')->name('live.destroy');
    });

    // ── Media library ────────────────────────────────────────────────────
    Route::controller(Admin\MediaController::class)->prefix('media')->name('media.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::patch('/{media}', 'update')->name('update');
        Route::delete('/{media}', 'destroy')->name('destroy');
    });

    // ── Comment moderation ───────────────────────────────────────────────
    Route::controller(Admin\CommentController::class)->prefix('comments')->name('comments.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::patch('/{comment}', 'update')->name('update');
        Route::post('/bulk', 'bulk')->name('bulk');
        Route::delete('/{comment}', 'destroy')->name('destroy');
    });

    // ── Taxonomy ─────────────────────────────────────────────────────────
    Route::controller(Admin\CategoryController::class)->prefix('categories')->name('categories.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('/reorder', 'reorder')->name('reorder');
        Route::put('/{category}', 'update')->name('update');
        Route::delete('/{category}', 'destroy')->name('destroy');
    });

    Route::controller(Admin\TaxonomyController::class)->prefix('taxonomy')->name('taxonomy.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/topics', 'storeTopic')->name('topics.store');
        Route::put('/topics/{topic}', 'updateTopic')->name('topics.update');
        Route::delete('/topics/{topic}', 'destroyTopic')->name('topics.destroy');
        Route::put('/tags/{tag}', 'updateTag')->name('tags.update');
        Route::post('/tags/{tag}/merge', 'mergeTag')->name('tags.merge');
        Route::delete('/tags/{tag}', 'destroyTag')->name('tags.destroy');
    });

    // ── Photo galleries ──────────────────────────────────────────────────
    // Bound by id, not slug: Gallery::getRouteKeyName() is `slug` for the
    // public reader, and an editor renaming a gallery would otherwise change
    // the URL of the form they are standing on.
    Route::controller(Admin\GalleryController::class)->prefix('galleries')->name('galleries.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('/{gallery:id}/edit', 'edit')->name('edit');
        Route::put('/{gallery:id}', 'update')->name('update');
        Route::delete('/{gallery:id}', 'destroy')->name('destroy');

        Route::post('/{gallery:id}/images', 'storeImages')->name('images.store');
        Route::post('/{gallery:id}/images/attach', 'attachImages')->name('images.attach');
        Route::post('/{gallery:id}/images/reorder', 'reorderImages')->name('images.reorder');
        Route::put('/images/{image}', 'updateImage')->name('images.update');
        Route::delete('/images/{image}', 'destroyImage')->name('images.destroy');
    });

    // ── E-paper ──────────────────────────────────────────────────────────
    // Bound by id like galleries: the public reader addresses an issue by
    // date, but an editor correcting a mistyped date would otherwise change
    // the URL of the form they are standing on.
    Route::controller(Admin\EpaperController::class)->prefix('epapers')->name('epapers.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('/{epaper:id}/edit', 'edit')->name('edit');
        Route::put('/{epaper:id}', 'update')->name('update');
        Route::delete('/{epaper:id}', 'destroy')->name('destroy');

        Route::post('/{epaper:id}/pages', 'storePages')->name('pages.store');
        Route::post('/{epaper:id}/pdf', 'storePdf')->name('pdf.store');
        Route::post('/{epaper:id}/pages/reorder', 'reorderPages')->name('pages.reorder');
        Route::put('/pages/{page}', 'updatePage')->name('pages.update');
        Route::delete('/pages/{page}', 'destroyPage')->name('pages.destroy');
    });

    // ── Front page layout ────────────────────────────────────────────────
    Route::controller(Admin\LayoutController::class)->prefix('layout')->name('layout.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('/reorder', 'reorder')->name('reorder');
        Route::put('/{block}', 'update')->name('update');
        Route::delete('/{block}', 'destroy')->name('destroy');
    });

    // ── Site management (admin only, enforced by UserPolicy) ─────────────
    Route::controller(Admin\UserController::class)->prefix('users')->name('users.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('/{user}', 'update')->name('update');
        Route::put('/{user}/password', 'resetPassword')->name('password');
        Route::delete('/{user}', 'destroy')->name('destroy');
    });

    Route::controller(Admin\AdController::class)->prefix('ads')->name('ads.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('/{ad}', 'update')->name('update');
        Route::delete('/{ad}', 'destroy')->name('destroy');
    });

    Route::controller(Admin\PageController::class)->prefix('pages')->name('pages.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('/{page}', 'update')->name('update');
        Route::delete('/{page}', 'destroy')->name('destroy');
    });

    Route::get('/settings', [Admin\SettingController::class, 'edit'])->name('settings');
    Route::put('/settings', [Admin\SettingController::class, 'update'])->name('settings.update');
});
