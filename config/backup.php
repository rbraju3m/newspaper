<?php

/**
 * Backups: where the copies go, and what notices when they stop.
 *
 * `backup:run` writes a verified dump and upload archive to local disk. That
 * survives a bad migration, a bad deploy and a bad DELETE. It does not survive
 * the server, which is what the off-site half below is for — and neither half
 * is worth anything if nobody notices the night it stops running, which is
 * what the heartbeat is for.
 *
 * Both are off when their setting is blank, which is the default. An install
 * that configures neither behaves exactly as it did before.
 */
return [

    /*
     * The off-site copy.
     *
     * Any S3-compatible bucket: AWS, DigitalOcean Spaces, Backblaze B2,
     * Wasabi, MinIO. The disk itself is defined in `config/filesystems.php`;
     * off-site is considered configured when that disk names a bucket.
     *
     * `prefix` is worth setting when one bucket receives more than one box —
     * without it a second server's `database/newspaper-2026-08-27-030000.sql.gz`
     * is the same object key as this one's.
     */
    'offsite' => [
        'disk' => env('BACKUP_OFFSITE_DISK', 'backups_offsite'),
        'prefix' => trim((string) env('BACKUP_OFFSITE_PREFIX', ''), '/'),

        /*
         * Retention on the remote, separately from the local window. Local
         * keeps 14 days because it is there to undo a mistake made this week;
         * the off-site copy is there for the disaster, and the disaster is
         * usually discovered later than that.
         */
        'keep_days' => (int) env('BACKUP_OFFSITE_KEEP', 30),
    ],

    /*
     * The dead man's switch.
     *
     * A URL that is requested only after a run has completed *and* verified —
     * dump, archive, and the off-site copy if one is configured. The point is
     * the request that does not arrive: an external service (healthchecks.io,
     * Cronitor, Better Stack, a self-hosted equivalent) alerts when the ping
     * it expected at 03:05 never came.
     *
     * That is the only failure the application genuinely cannot report on its
     * own. A backup that fails loudly reaches `ErrorAlerter`; a backup whose
     * cron entry was removed, whose box is powered off, or whose disk filled
     * up mid-run reports nothing at all, because nothing runs.
     */
    'heartbeat' => [
        'url' => env('BACKUP_HEARTBEAT_URL'),
        'timeout' => (int) env('BACKUP_HEARTBEAT_TIMEOUT', 10),
    ],

];
