<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Database dumps and upload archives. Deliberately not under
         * `app/public`: that tree is symlinked into the document root by
         * `storage:link`, so a backup written there would be downloadable by
         * anyone who guessed the filename — the whole database, over HTTP.
         */
        'backups' => [
            'driver' => 'local',
            'root' => storage_path('app/backups'),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Where the nightly backup is copied so it survives this machine.
         *
         * Any S3-compatible bucket. Its own credentials rather than the `s3`
         * disk's, deliberately: the destination for backups is usually a
         * different bucket — often a different provider — from anything the
         * application itself would read or write, and it wants a key that can
         * only append to it.
         *
         * `throw` is true here and false on every other disk. Elsewhere a
         * failed write returning false is a degraded page; on a backup
         * destination a silent false is a night with no off-site copy that
         * reported success, which is the exact failure this whole feature
         * exists to prevent.
         */
        'backups_offsite' => [
            'driver' => 's3',
            'key' => env('BACKUP_OFFSITE_KEY'),
            'secret' => env('BACKUP_OFFSITE_SECRET'),
            'region' => env('BACKUP_OFFSITE_REGION'),
            'bucket' => env('BACKUP_OFFSITE_BUCKET'),
            'endpoint' => env('BACKUP_OFFSITE_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('BACKUP_OFFSITE_PATH_STYLE', false),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
