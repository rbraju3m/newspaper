<?php

/**
 * Web Push (VAPID).
 *
 * Deployment-level rather than editor-level: the key pair identifies this
 * application to every push service, so it belongs in `.env` beside the app
 * key and not in the `settings` table where an editor could rotate it by
 * accident. Rotating it silently invalidates every subscription on the site —
 * browsers will not accept a message signed by a key they did not subscribe
 * under, and there is no way to tell them the key changed.
 *
 * `php artisan push:keys` generates a pair. Until both halves are set the
 * feature is *off* rather than broken: the subscribe endpoints refuse, the
 * reader is never shown a toggle, and `push:send` says so instead of
 * pretending to deliver.
 */
return [
    /**
     * A `mailto:` or `https:` URL identifying whoever operates this server.
     * Push services use it to reach a human when a sender misbehaves, and
     * some of them reject a subscription request without one.
     */
    'subject' => env('PUSH_SUBJECT', env('SITE_EMAIL') ? 'mailto:'.env('SITE_EMAIL') : env('APP_URL')),

    'public_key' => env('PUSH_PUBLIC_KEY'),
    'private_key' => env('PUSH_PRIVATE_KEY'),

    /**
     * How long a push service should hold an undelivered message for. Breaking
     * news that arrives six hours late is not breaking news, and a phone that
     * has been off that long will see the story on the homepage anyway.
     */
    'ttl' => (int) env('PUSH_TTL', 3600),

    /**
     * Subscriptions per flush. The library batches into one curl_multi run, so
     * this is a memory and latency knob rather than a rate limit: too large and
     * one slow push service holds the whole batch open.
     */
    'batch' => (int) env('PUSH_BATCH', 500),

    /**
     * Seconds to wait on any one push service before giving up on it.
     */
    'timeout' => (int) env('PUSH_TIMEOUT', 15),
];
