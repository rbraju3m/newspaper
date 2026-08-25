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

Route::middleware(['auth', 'staff'])->prefix('admin')->name('admin.')->group(function () {

    Route::get('/', Admin\DashboardController::class)->name('dashboard');

    // ── Articles ─────────────────────────────────────────────────────────
    Route::controller(Admin\ArticleController::class)->prefix('articles')->name('articles.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/{article}/edit', 'edit')->name('edit');
        Route::put('/{article}', 'update')->name('update');
        Route::patch('/{article}/status', 'status')->name('status');
        Route::delete('/{article}', 'destroy')->name('destroy');
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
