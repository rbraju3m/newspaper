<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * The first and only scheduled task in this application.
 *
 * It fires only if the cron entry in `docs/DEPLOY.md` is installed —
 * `schedule:run` is what turns this file into anything at all, and nothing
 * here runs without it. `php artisan schedule:list` says what is registered;
 * it does not say whether cron is calling it.
 *
 * Output goes to its own log rather than the application log, so a failed
 * dump is legible instead of buried among request errors.
 */
Schedule::command('backup:run')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/backup.log'));
