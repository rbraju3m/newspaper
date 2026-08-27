<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * What `/up` actually checks.
 *
 * Laravel's health route answers 200 as soon as the framework boots, which is
 * a narrower claim than it looks: PHP is running and the container built. An
 * external uptime check watching that endpoint would have sat green through a
 * dead MySQL, a full disk, and every outage a reader would actually notice.
 *
 * Laravel turns a throw from here into a 500 with `status: down`, and reports
 * it — so the same fault also reaches whoever is on call through
 * `ErrorAlerter`, throttled to one an hour rather than one per poll.
 *
 * Two rules for anything added here:
 *
 * - **It has to be cheap.** This runs on every poll of an uptime monitor,
 *   which is usually once a minute for ever. One indexed round trip per
 *   dependency, no application queries, nothing that writes a row.
 * - **It has to be a dependency the site genuinely cannot serve without.**
 *   A check that goes red while readers are being served fine trains everyone
 *   to ignore the alert, and after that the endpoint is decoration.
 *
 * Deliberately not checked: SMTP, the push service and the off-site bucket.
 * All three can be down for an hour without a reader noticing, and none of
 * them can be probed without doing something with a side effect.
 */
class DiagnoseHealth
{
    public function handle(): void
    {
        $this->database();
        $this->cache();
        $this->storage();
    }

    private function database(): void
    {
        try {
            DB::select('select 1');
        } catch (\Throwable $e) {
            throw new RuntimeException('database unreachable: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * A round trip rather than a connection check.
     *
     * `CACHE_STORE` is the database here, so this mostly re-proves the check
     * above — but it is the store the homepage is served out of, and it is the
     * one that stops working first when the `cache` table is locked or the
     * disk behind it is full.
     */
    private function cache(): void
    {
        $key = 'health.probe';

        try {
            Cache::put($key, 1, 10);

            if (Cache::get($key) !== 1) {
                throw new RuntimeException('cache did not return what was written to it');
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('cache unusable: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * A full disk is an outage that looks like nothing else.
     *
     * Sessions, the cache table, the log and every upload all stop at once,
     * and the symptom is whichever of them the reader touched first.
     */
    private function storage(): void
    {
        foreach ([storage_path('logs'), storage_path('framework')] as $path) {
            if (! is_writable($path)) {
                throw new RuntimeException('storage is not writable: '.$path);
            }
        }
    }
}
