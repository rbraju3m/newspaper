<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Scheduled tasks.
 *
 * It fires only if the cron entry in `docs/DEPLOY.md` is installed —
 * `schedule:run` is what turns this file into anything at all, and nothing
 * here runs without it. `php artisan schedule:list` says what is registered;
 * it does not say whether cron is calling it.
 *
 * Output goes to its own log rather than the application log, so a failed
 * dump is legible instead of buried among request errors.
 */
/*
 * Scheduled stories, published on time.
 *
 * Every minute, because a newsroom that schedules to the minute expects the
 * minute — and because the query is one indexed lookup that almost always
 * returns nothing. withoutOverlapping() so a slow run cannot double-publish.
 */
Schedule::command('articles:publish-due')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('backup:run')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/backup.log'));

/*
 * The morning digest: what broke yesterday, grouped.
 *
 * The push alert answers "is something on fire right now" and deliberately
 * says nothing twice — this is how the faults it silenced still get seen.
 * Skipped entirely when no address is configured, rather than mailing nowhere.
 */
Schedule::command('errors:digest', ['--days' => 1, '--email' => (string) config('errors.alert.email')])
    ->dailyAt('07:00')
    ->when(fn () => filled(config('errors.alert.email')));
