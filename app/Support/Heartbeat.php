<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The ping that says last night's backup happened.
 *
 * A dead man's switch, so the signal is the request that *does not* arrive.
 * Everything else about a backup can be reported by the application itself:
 * a failed dump throws, gets caught, and reaches `ErrorAlerter`. What no
 * amount of application code can report is the run that never started —
 * a removed cron entry, a powered-off box, a disk that filled up between
 * midnight and three. Nothing runs, so nothing tells you.
 *
 * An external service expecting a ping at 03:05 notices that on the first
 * night. This is the only part of the monitoring story that has to live
 * outside the machine being monitored.
 */
class Heartbeat
{
    /**
     * @return bool whether the ping was sent; false also means "not configured"
     */
    public static function ping(?string $suffix = null): bool
    {
        $url = (string) config('backup.heartbeat.url');

        if ($url === '') {
            return false;
        }

        // healthchecks.io and Cronitor both use `/fail` and `/start` suffixes
        // on the same base URL, which is why this appends rather than takes a
        // second setting.
        if ($suffix !== null) {
            $url = rtrim($url, '/').'/'.ltrim($suffix, '/');
        }

        try {
            $response = Http::timeout((int) config('backup.heartbeat.timeout', 10))
                ->withoutRedirecting()
                ->get($url);

            if ($response->failed()) {
                Log::warning('Backup heartbeat rejected.', ['status' => $response->status()]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            // A monitoring endpoint being unreachable must never be the reason
            // a good backup reports failure. Log it and carry on: the external
            // service will notice the missing ping by itself, which is the
            // whole design.
            Log::warning('Backup heartbeat unreachable: '.$e->getMessage());

            return false;
        }
    }
}
