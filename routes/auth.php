<?php

use App\Http\Controllers\Account;
use App\Http\Controllers\Auth;
use App\Http\Controllers\Site\CommentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication & reader account
|--------------------------------------------------------------------------
|
| Required from routes/web.php BEFORE the catch-all {category} route, which
| would otherwise resolve /login as a category slug.
|
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [Auth\AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [Auth\AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('login.store');

    Route::get('/register', [Auth\RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [Auth\RegisteredUserController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('register.store');

    Route::get('/forgot-password', [Auth\PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [Auth\PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');

    Route::get('/reset-password/{token}', [Auth\NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [Auth\NewPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.store');

    Route::get('/auth/{provider}/redirect', [Auth\SocialiteController::class, 'redirect'])->name('oauth.redirect');
    Route::get('/auth/{provider}/callback', [Auth\SocialiteController::class, 'callback'])->name('oauth.callback');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [Auth\AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // ── Email verification ───────────────────────────────────────────────
    Route::get('/verify-email', [Auth\EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [Auth\EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/verify-email/resend', [Auth\EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // ── Reader account ───────────────────────────────────────────────────
    Route::prefix('account')->name('account.')->group(function () {
        Route::get('/', [Account\ProfileController::class, 'index'])->name('index');
        Route::patch('/', [Account\ProfileController::class, 'update'])->name('update');
        Route::put('/password', [Account\ProfileController::class, 'updatePassword'])->name('password.update');
        Route::delete('/', [Account\ProfileController::class, 'destroy'])->name('destroy');

        Route::get('/bookmarks', [Account\BookmarkController::class, 'index'])->name('bookmarks');
        Route::delete('/bookmarks/{article}', [Account\BookmarkController::class, 'destroy'])->name('bookmarks.destroy');

        Route::get('/history', [Account\HistoryController::class, 'index'])->name('history');
        Route::delete('/history/{article}', [Account\HistoryController::class, 'destroy'])->name('history.destroy');
        Route::delete('/history', [Account\HistoryController::class, 'clear'])->name('history.clear');

        Route::get('/preferences', [Account\PreferenceController::class, 'edit'])->name('preferences');
        Route::patch('/preferences', [Account\PreferenceController::class, 'update'])->name('preferences.update');
    });

    // ── Comments ─────────────────────────────────────────────────────────
    Route::post('/articles/{article}/comments', [CommentController::class, 'store'])->name('comments.store');
    Route::patch('/comments/{comment}', [CommentController::class, 'update'])->name('comments.update');
    Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');
    Route::post('/comments/{comment}/like', [CommentController::class, 'like'])->name('comments.like');
    Route::post('/comments/{comment}/report', [CommentController::class, 'report'])->name('comments.report');

    // Reading progress, posted by sendBeacon from the article page.
    Route::post('/articles/{article}/read', [Account\HistoryController::class, 'track'])->name('history.track');
});

// Bookmark toggle answers 401 for guests so the Alpine store can roll back and
// redirect; it therefore sits outside the auth group.
Route::post('/account/bookmarks/{article}', [Account\BookmarkController::class, 'toggle'])
    ->name('account.bookmarks.toggle');

// The admin panel lives in its own route file.
require __DIR__.'/admin.php';
