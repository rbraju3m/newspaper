<?php

namespace App\Console\Commands;

use App\Exceptions\BackupFailed;
use App\Services\ErrorAlerter;
use App\Support\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Database dump and upload archive, verified after writing.
 *
 * Two things have to be backed up and only one of them is in the database. The
 * `uploads/` tree — originals plus the whole WebP derivative ladder — is in
 * neither `mysqldump` output nor git, so a restore from a SQL dump alone gives
 * you a newspaper whose every image is a broken link.
 *
 * The verification is the point of this command rather than a nicety. A
 * truncated dump is a valid gzip file of a plausible size that restores into a
 * half-empty database, and the failure is discovered on the day it is needed.
 * Every artifact here is decompressed and checked before the command reports
 * success, and anything that fails is deleted rather than left looking like a
 * backup.
 *
 * Writes locally first, always. A backup on the same disk as the database
 * survives a bad migration and not a dead server, so when off-site storage is
 * configured this hands the verified artifacts to `backup:sync` before it
 * reports success — the local copy is the one that can be checked, and only
 * something that passed goes up.
 *
 * Success here means all of it: dumped, archived, verified, and off the
 * machine. That is what makes the heartbeat at the end worth anything.
 */
class RunBackup extends Command
{
    /** mysqldump writes this as its last line. Its absence means truncation. */
    private const COMPLETION_MARKER = 'Dump completed';

    /** Anything smaller than this is not a database. */
    private const MIN_DUMP_BYTES = 1024;

    protected $signature = 'backup:run
        {--database : Back up only the database}
        {--files : Back up only the uploads}
        {--keep=14 : Delete backups older than this many days}
        {--disk=backups : Filesystem disk to write to}
        {--no-offsite : Skip the off-site copy even when one is configured}';

    protected $description = 'Dump the database and archive uploads, then verify both';

    public function handle(ErrorAlerter $alerter): int
    {
        $disk = (string) $this->option('disk');

        if (($guard = $this->guardDestination($disk)) !== null) {
            $this->components->error($guard);

            return self::FAILURE;
        }

        // Neither flag means both, which is what a cron entry wants.
        $wantsDatabase = $this->option('database') || ! $this->option('files');
        $wantsFiles = $this->option('files') || ! $this->option('database');

        $stamp = now()->format('Y-m-d-His');
        $failed = false;

        if ($wantsDatabase) {
            $failed = ! $this->backupDatabase($disk, $stamp) || $failed;
        }

        if ($wantsFiles) {
            $failed = ! $this->backupFiles($disk, $stamp) || $failed;
        }

        $this->prune($disk);

        if ($failed) {
            $this->newLine();
            $this->components->error('Backup incomplete. Nothing above marked OK should be relied on.');

            return $this->giveUp($alerter, 'Nightly backup failed. See storage/logs/backup.log.');
        }

        // Off-site last, and only for artifacts that already verified. It
        // reports and alerts on its own failures, so this only has to fail.
        if (! $this->option('no-offsite') && $this->call('backup:sync', ['--from' => $disk]) !== self::SUCCESS) {
            return $this->giveUp($alerter, null);
        }

        $this->newLine();
        $this->components->info('Backup complete: '.Storage::disk($disk)->path(''));

        Heartbeat::ping();

        return self::SUCCESS;
    }

    /**
     * Report a failed run through every channel that exists, then fail.
     *
     * Two channels, because they catch different things. `ErrorAlerter` says
     * what went wrong and is throttled to one an hour, which suits a nightly
     * job that keeps failing the same way. The heartbeat's `/fail` says only
     * that tonight did not work — but it is the same switch that would have
     * caught the run never starting at all, so the external service has one
     * thing to watch rather than two.
     */
    private function giveUp(ErrorAlerter $alerter, ?string $message): int
    {
        if ($message !== null) {
            $alerter->report(new BackupFailed($message));
        }

        Heartbeat::ping('fail');

        return self::FAILURE;
    }

    /**
     * Refuse to write where the web server can serve it.
     *
     * `storage:link` symlinks `app/public` into the document root, so a dump
     * written under it is one guessed filename away from being downloaded.
     */
    private function guardDestination(string $disk): ?string
    {
        if (! array_key_exists($disk, config('filesystems.disks', []))) {
            return "No such disk '{$disk}'. Configured: ".implode(', ', array_keys(config('filesystems.disks'))).'.';
        }

        $root = realpath(Storage::disk($disk)->path('')) ?: Storage::disk($disk)->path('');
        $public = realpath(storage_path('app/public')) ?: storage_path('app/public');

        if (str_starts_with($root, $public)) {
            return "Disk '{$disk}' writes inside the public tree, which is served over HTTP. Refusing.";
        }

        return null;
    }

    private function backupDatabase(string $disk, string $stamp): bool
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if (($config['driver'] ?? null) !== 'mysql') {
            $this->components->error("Only MySQL is supported; the default connection is '{$connection}'.");

            return false;
        }

        if (! $this->binaryExists('mysqldump')) {
            $this->components->error('mysqldump not found on PATH.');

            return false;
        }

        $target = 'database/'.$config['database'].'-'.$stamp.'.sql.gz';
        Storage::disk($disk)->makeDirectory('database');
        $path = Storage::disk($disk)->path($target);

        // The password goes in a 0600 file rather than on the command line,
        // where `ps` would show it to every user on the box.
        $credentials = $this->credentialsFile($config);

        try {
            $process = Process::fromShellCommandline(
                sprintf(
                    'mysqldump --defaults-extra-file=%s %s %s | gzip > %s',
                    escapeshellarg($credentials),
                    implode(' ', $this->dumpFlags()),
                    escapeshellarg($config['database']),
                    escapeshellarg($path),
                ),
            );

            $process->setTimeout(3600);
            $process->run();

            if (! $process->isSuccessful()) {
                $this->discard($disk, $target, 'mysqldump failed: '.trim($process->getErrorOutput()));

                return false;
            }
        } finally {
            @unlink($credentials);
        }

        return $this->verifyDump($disk, $target);
    }

    /**
     * `--single-transaction` takes the dump inside one consistent read rather
     * than locking the newsroom out mid-edit. `--no-tablespaces` keeps
     * mysqldump from reading INFORMATION_SCHEMA.FILES, which needs a global
     * PROCESS grant the deployment's database-scoped user does not have.
     * `--default-character-set` is not optional with Bangla in every column.
     *
     * @return list<string>
     */
    private function dumpFlags(): array
    {
        return [
            '--single-transaction',
            '--quick',
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            '--routines',
            '--events',
        ];
    }

    private function credentialsFile(array $config): string
    {
        $file = tempnam(sys_get_temp_dir(), 'backup-my-');

        file_put_contents($file, implode("\n", [
            '[client]',
            'host="'.($config['host'] ?? '127.0.0.1').'"',
            'port="'.($config['port'] ?? 3306).'"',
            'user="'.($config['username'] ?? '').'"',
            'password="'.($config['password'] ?? '').'"',
            '',
        ]));

        chmod($file, 0600);

        return $file;
    }

    /**
     * A truncated dump is a valid gzip file of a plausible size. The only
     * cheap proof it finished is mysqldump's own closing line.
     */
    private function verifyDump(string $disk, string $target): bool
    {
        $path = Storage::disk($disk)->path($target);
        $size = Storage::disk($disk)->size($target);

        if ($size < self::MIN_DUMP_BYTES) {
            $this->discard($disk, $target, 'dump is only '.$size.' bytes');

            return false;
        }

        if (! $this->shell(['gzip', '-t', $path])) {
            $this->discard($disk, $target, 'gzip reports the archive is corrupt');

            return false;
        }

        $tail = Process::fromShellCommandline('gzip -cd '.escapeshellarg($path).' | tail -c 4096');
        $tail->run();

        if (! str_contains($tail->getOutput(), self::COMPLETION_MARKER)) {
            $this->discard($disk, $target, 'dump has no completion marker — it was truncated');

            return false;
        }

        $this->components->twoColumnDetail('<fg=green>database</> '.$target, $this->human($size));

        return true;
    }

    private function backupFiles(string $disk, string $stamp): bool
    {
        $source = storage_path('app/public');

        if (! is_dir($source.'/uploads')) {
            $this->components->twoColumnDetail('<fg=yellow>uploads</>', 'nothing to archive');

            return true;
        }

        $target = 'files/uploads-'.$stamp.'.tar.gz';
        Storage::disk($disk)->makeDirectory('files');
        $path = Storage::disk($disk)->path($target);

        // -C so the archive holds `uploads/...` and not the absolute path,
        // which is what makes it restorable onto a different box.
        if (! $this->shell(['tar', '-czf', $path, '-C', $source, 'uploads'])) {
            $this->discard($disk, $target, 'tar failed');

            return false;
        }

        if (! $this->shell(['tar', '-tzf', $path])) {
            $this->discard($disk, $target, 'the archive does not list');

            return false;
        }

        $this->components->twoColumnDetail(
            '<fg=green>uploads</> '.$target,
            $this->human(Storage::disk($disk)->size($target)),
        );

        return true;
    }

    /**
     * Delete by age, but never the newest of each kind — a --keep short enough
     * to catch today's backup would otherwise leave none at all.
     */
    private function prune(string $disk): void
    {
        $days = max(0, (int) $this->option('keep'));

        if ($days === 0) {
            return;
        }

        $cutoff = Carbon::now()->subDays($days)->getTimestamp();
        $removed = 0;
        $reclaimed = 0;

        foreach (['database', 'files'] as $folder) {
            $files = collect(Storage::disk($disk)->files($folder))
                ->sortByDesc(fn (string $file) => Storage::disk($disk)->lastModified($file))
                ->values();

            foreach ($files->skip(1) as $file) {
                if (Storage::disk($disk)->lastModified($file) < $cutoff) {
                    $reclaimed += Storage::disk($disk)->size($file);
                    Storage::disk($disk)->delete($file);
                    $removed++;
                }
            }
        }

        if ($removed > 0) {
            $this->components->twoColumnDetail(
                'pruned (older than '.$days.' days)',
                $removed.' file(s), '.$this->human($reclaimed).' reclaimed',
            );
        }
    }

    /** Delete the artifact and say why: a bad backup must not look like one. */
    private function discard(string $disk, string $target, string $reason): void
    {
        Storage::disk($disk)->delete($target);
        $this->components->error($target.' — '.$reason.'. Removed.');
    }

    /** @param  list<string>  $command */
    private function shell(array $command): bool
    {
        $process = new Process($command);
        $process->setTimeout(3600);
        $process->run();

        return $process->isSuccessful();
    }

    private function binaryExists(string $binary): bool
    {
        return $this->shell(['which', $binary]);
    }

    private function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, $unit === 'B' ? 0 : 1).' '.$unit;
            }

            $bytes /= 1024;
        }

        return $bytes.' B';
    }
}
