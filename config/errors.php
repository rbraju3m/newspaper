<?php

/**
 * Error alerting.
 *
 * The gap this closes: until now nothing reported an exception anywhere but
 * `storage/logs/laravel.log`, so nobody learned that a nightly backup had
 * failed — or that anything else had — until they went looking.
 *
 * Every channel is off when its setting is blank, which is the default. An
 * unconfigured install behaves exactly as it did before.
 */
return [

    /*
     * Where an alert goes. Both may be set; both may be blank.
     *
     * Alerts are sent synchronously from the exception handler, because there
     * is no queue worker on this deployment. That is why the timeouts below
     * are short: a dead webhook must not turn a 500 into a hang.
     */
    'alert' => [
        'email' => env('ERROR_ALERT_EMAIL'),
        'webhook' => env('ERROR_ALERT_WEBHOOK'),
        'webhook_timeout' => (int) env('ERROR_ALERT_WEBHOOK_TIMEOUT', 5),
    ],

    /*
     * One alert per distinct error per this many minutes. The fingerprint is
     * the exception class, file and line — not the message, which usually
     * carries an id and would make every occurrence look new.
     */
    'throttle_minutes' => (int) env('ERROR_ALERT_THROTTLE', 60),

    /*
     * A ceiling across all errors, so a fault that produces a hundred distinct
     * fingerprints an hour cannot turn into a hundred emails. Everything over
     * the cap is still written to the error log and still reaches the digest.
     */
    'max_per_hour' => (int) env('ERROR_ALERT_MAX_PER_HOUR', 20),

    /*
     * Throttling state lives in the *file* cache rather than the default
     * store, deliberately. The default store is the database, and a database
     * outage is precisely the failure you most need an alert about — asking
     * the database whether you may report that the database is down is not a
     * question that gets answered.
     */
    'cache_store' => env('ERROR_ALERT_CACHE_STORE', 'file'),

    /*
     * Never alert on these.
     *
     * Laravel already declines to report the obvious noise — 404s, validation
     * failures, 419s, auth and authorisation failures — before any of this
     * runs, so this list is for application-specific additions rather than a
     * reimplementation of that.
     */
    'ignore' => [
        // App\Exceptions\SomethingExpected::class,
    ],

];
