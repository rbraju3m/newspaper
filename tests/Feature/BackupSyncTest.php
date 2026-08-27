<?php

namespace Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Tests\TestCase;

/**
 * `backup:sync` — the copy that has to survive the machine, and the heartbeat
 * that notices when none of it ran.
 *
 * `DatabaseTruncation` for the same reason `BackupTest` uses it: `backup:run`
 * shells out to mysqldump on its own connection, so anything written inside
 * `RefreshDatabase`'s uncommitted transaction is invisible to it.
 *
 * The remote is a local directory throughout. What is being tested is this
 * command's contract — what it uploads, what it skips, what it refuses to
 * leave in place — and none of that is S3-specific; putting a real bucket
 * behind it would test the network and Amazon rather than any of this. The one
 * thing a local stand-in cannot reproduce is the multipart ETag, which is
 * exactly why `verify()` falls back to size when it sees one.
 */
class BackupSyncTest extends TestCase
{
    use DatabaseTruncation;

    private string $remoteRoot;

    private string $errorLog;

    protected function setUp(): void
    {
        parent::setUp();

        // Real directories, not in-memory fakes: mysqldump and tar write
        // through the shell, so both ends have to exist on the filesystem.
        Storage::fake('backups');

        $this->remoteRoot = storage_path('framework/testing/offsite-'.getmypid());
        $this->rmdir($this->remoteRoot);
        @mkdir($this->remoteRoot, 0777, true);

        // Its own error log, and deliberately not the `errors-<pid>.log` name
        // ErrorAlertTest uses. A failing backup here reports through
        // ErrorAlerter like any other fault, and sharing that filename put this
        // file's alerts into that one's assertions — same process, same pid.
        $this->errorLog = storage_path('framework/testing/backup-errors-'.getmypid().'.log');
        @mkdir(dirname($this->errorLog), 0777, true);
        @unlink($this->errorLog);
        config(['logging.channels.errors.path' => $this->errorLog]);
        Log::forgetChannel('errors');

        // `bucket` is what marks off-site as configured, so the stand-in has to
        // carry one even though a local disk has no such thing.
        config([
            'filesystems.disks.offsite_test' => [
                'driver' => 'local',
                'root' => $this->remoteRoot,
                'bucket' => 'stand-in',
                'throw' => true,
            ],
            'backup.offsite.disk' => 'offsite_test',
            'backup.offsite.prefix' => '',
            'backup.heartbeat.url' => null,
        ]);

        Http::fake();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->remoteRoot);

        Log::forgetChannel('errors');   // release the handle before deleting
        @unlink($this->errorLog);

        parent::tearDown();
    }

    private function rmdir(string $path): void
    {
        if (is_dir($path)) {
            shell_exec('rm -rf '.escapeshellarg($path));
        }
    }

    /** A verified local dump, without going off-site yet. */
    private function localBackup(): void
    {
        Storage::disk('public')->put('uploads/2026/08/demo/a.jpg', 'x');
        $this->artisan('backup:run --database --no-offsite')->assertSuccessful();
    }

    /** @return list<string> every object on the stand-in remote */
    private function remoteFiles(): array
    {
        $found = [];

        foreach (['database', 'files'] as $folder) {
            foreach (Storage::disk('offsite_test')->files($folder) as $file) {
                $found[] = $file;
            }
        }

        sort($found);

        return $found;
    }

    // ── Copying ──────────────────────────────────────────────────────────

    public function test_it_copies_verified_artifacts_to_the_remote(): void
    {
        $this->localBackup();

        $this->artisan('backup:sync')->assertSuccessful();

        $this->assertCount(1, $this->remoteFiles());
        $this->assertStringStartsWith('database/', $this->remoteFiles()[0]);
    }

    public function test_the_copy_is_byte_for_byte_what_was_dumped(): void
    {
        $this->localBackup();
        $this->artisan('backup:sync --verify=download')->assertSuccessful();

        $local = Storage::disk('backups')->files('database')[0];

        $this->assertSame(
            hash_file('sha256', Storage::disk('backups')->path($local)),
            hash_file('sha256', Storage::disk('offsite_test')->path($local)),
        );
    }

    public function test_a_second_run_uploads_nothing(): void
    {
        $this->localBackup();
        $this->artisan('backup:sync')->assertSuccessful();

        $before = Storage::disk('offsite_test')->lastModified($this->remoteFiles()[0]);

        $this->artisan('backup:sync')
            ->expectsOutputToContain('already off-site')
            ->assertSuccessful();

        $this->assertSame($before, Storage::disk('offsite_test')->lastModified($this->remoteFiles()[0]));
    }

    public function test_a_dry_run_copies_nothing(): void
    {
        $this->localBackup();

        $this->artisan('backup:sync --dry-run')->assertSuccessful();

        $this->assertSame([], $this->remoteFiles());
    }

    /**
     * The whole point of the feature, in one assertion: an install that has
     * not configured off-site storage must not fail its nightly cron every
     * night to say so.
     */
    public function test_an_unconfigured_remote_skips_rather_than_failing(): void
    {
        config(['filesystems.disks.offsite_test.bucket' => null]);
        $this->localBackup();

        $this->artisan('backup:sync')
            ->expectsOutputToContain('not configured')
            ->assertSuccessful();

        $this->assertSame([], $this->remoteFiles());
    }

    // ── Refusing a copy it cannot prove ──────────────────────────────────

    /**
     * A truncated upload is the realistic remote failure, and it is the one
     * that matters most: a short object sits in the bucket looking exactly
     * like a backup until the day somebody restores from it.
     *
     * Faked with a disk driver that writes half of what it is given, which is
     * the only way to produce the condition without a network to interrupt.
     */
    public function test_a_copy_that_arrives_truncated_is_deleted_and_the_command_fails(): void
    {
        Storage::extend('truncating', function ($app, array $config) {
            $adapter = new LocalFilesystemAdapter($config['root']);

            return new class(new Flysystem($adapter), $adapter, $config) extends FilesystemAdapter
            {
                public function writeStream($path, $resource, array $options = [])
                {
                    $all = (string) stream_get_contents($resource);

                    return $this->put($path, substr($all, 0, max(1, intdiv(strlen($all), 2))), $options);
                }
            };
        });

        config(['filesystems.disks.offsite_test.driver' => 'truncating']);

        $this->localBackup();

        $this->artisan('backup:sync')
            ->expectsOutputToContain('Removed from the remote')
            ->assertFailed();

        $this->assertSame([], $this->remoteFiles());
    }

    // ── Retention ────────────────────────────────────────────────────────

    public function test_remote_pruning_keeps_the_newest_even_when_it_is_older_than_keep(): void
    {
        $this->localBackup();
        $this->artisan('backup:sync')->assertSuccessful();

        // Age the only copy well past the window.
        $only = $this->remoteFiles()[0];
        touch(Storage::disk('offsite_test')->path($only), now()->subDays(400)->getTimestamp());

        $this->artisan('backup:sync --keep=30')->assertSuccessful();

        $this->assertCount(1, $this->remoteFiles());
    }

    // ── The nightly run as one unit ──────────────────────────────────────

    public function test_backup_run_takes_the_artifacts_off_site_itself(): void
    {
        Storage::disk('public')->put('uploads/2026/08/demo/a.jpg', 'x');

        $this->artisan('backup:run --database')->assertSuccessful();

        $this->assertCount(1, $this->remoteFiles());
    }

    public function test_no_offsite_leaves_the_remote_alone(): void
    {
        $this->localBackup();

        $this->assertSame([], $this->remoteFiles());
    }

    // ── The heartbeat ────────────────────────────────────────────────────

    public function test_a_completed_run_pings_the_heartbeat(): void
    {
        config(['backup.heartbeat.url' => 'https://hc.example/abc123']);

        $this->artisan('backup:run --database')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === 'https://hc.example/abc123');
    }

    public function test_a_failed_run_pings_the_failure_endpoint_instead(): void
    {
        config([
            'backup.heartbeat.url' => 'https://hc.example/abc123',
            'database.default' => 'sqlite',   // backup:run supports MySQL only
        ]);

        $this->artisan('backup:run --database')->assertFailed();

        Http::assertSent(fn ($request) => $request->url() === 'https://hc.example/abc123/fail');
    }

    public function test_an_unreachable_heartbeat_does_not_fail_a_good_backup(): void
    {
        config(['backup.heartbeat.url' => 'https://hc.example/abc123']);
        Http::fake(['*' => Http::response('no', 500)]);

        $this->artisan('backup:run --database')->assertSuccessful();
    }

    public function test_no_heartbeat_configured_sends_nothing(): void
    {
        $this->artisan('backup:run --database')->assertSuccessful();

        Http::assertNothingSent();
    }

    // ── Alerting ─────────────────────────────────────────────────────────

    /**
     * `config/errors.php` says in its own docblock that the gap it closes is
     * nobody learning a nightly backup had failed. Until this, nothing wired
     * the two together and a failed dump reached a log file and stopped.
     */
    public function test_a_failed_backup_reaches_whoever_is_on_call(): void
    {
        config([
            'errors.alert.email' => 'oncall@newsroom.example',
            'errors.alert.webhook' => null,
            'errors.ignore' => [],
            'database.default' => 'sqlite',
        ]);

        Cache::store('file')->flush();   // the alerter throttles on this store

        $this->artisan('backup:run --database')->assertFailed();

        Mail::assertSentCount(1);
    }
}
