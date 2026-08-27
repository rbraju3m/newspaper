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

/*
 * Counters, reconciled before the backup rather than after — so what gets
 * dumped is the corrected data, not a night-old drift.
 *
 * Article::booted() and Comment::booted() keep these right as they go; this
 * is for what events cannot see (bulk updates, imports, a bug in the hooks).
 * Quiet when there is nothing to fix, so a line in backup.log means something.
 */
Schedule::command('counters:recompute --quiet-when-clean')
    ->dailyAt('02:45')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/backup.log'));

/*
 * The nightly backup, and the only entry that needs to exist for it.
 *
 * `backup:run` dumps, archives, verifies both, and then hands the verified
 * artifacts to `backup:sync` itself when off-site storage is configured — so
 * there is one schedule line, one log, and one exit code meaning "there is a
 * checked backup and it is not on this machine". A second entry for the sync
 * could succeed on a night the dump failed, which is the reading nobody wants.
 *
 * Failures reach `ErrorAlerter`. The run that never starts at all — cron
 * removed, box off, disk full at midnight — reaches nobody, which is what the
 * heartbeat is for: an external service alerts on the ping that does not come.
 */
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

/*
 * The newsletter.
 *
 * 07:30, after the error digest rather than before it: if last night broke
 * something, whoever is on call has had half an hour with it before several
 * thousand readers are mailed a link into the same site.
 *
 * Sent inline — no queue worker runs here — so this holds the scheduler for as
 * long as the send takes. `withoutOverlapping()` is what stops tomorrow's run
 * starting on top of a today's that is still going, which would mail the
 * unstamped tail of the list twice.
 *
 * A quiet news day sends nothing at all: the command skips any subscriber
 * whose edition comes back empty, rather than mailing them a page of nothing.
 */
Schedule::command('newsletter:send --frequency=daily')
    ->dailyAt('07:30')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/newsletter.log'));

// Fridays, because the Bangladeshi week ends on one and a "week in review"
// arriving mid-week is a week in review of nothing anybody remembers.
Schedule::command('newsletter:send --frequency=weekly')
    ->weeklyOn(5, '08:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/newsletter.log'));
