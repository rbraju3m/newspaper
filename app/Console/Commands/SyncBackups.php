<?php

namespace App\Console\Commands;

use App\Exceptions\BackupFailed;
use App\Services\ErrorAlerter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Copies verified backups off this machine.
 *
 * `backup:run` writes to local disk and proves the artifacts are intact. That
 * covers a bad migration, a bad deploy and a bad DELETE — every failure except
 * the one where the disk holding both the database and its backups stops
 * existing. This is that one.
 *
 * It copies rather than streams straight to the bucket, and the ordering is
 * deliberate: an artifact is verified locally first, and only something that
 * passed goes up. A `mysqldump | gzip | aws s3 cp` pipeline cannot check its
 * own completion marker, so it uploads truncated dumps with total confidence.
 *
 * Idempotent by size. A re-run after a half-finished night uploads what is
 * missing and skips what is already there, which is what makes it safe to put
 * in cron next to a command that may itself have partly failed.
 */
class SyncBackups extends Command
{
    protected $signature = 'backup:sync
        {--from=backups : Local disk holding the verified artifacts}
        {--to= : Remote disk to copy to; defaults to config(backup.offsite.disk)}
        {--keep= : Delete remote copies older than this many days}
        {--verify=size : How to prove the copy arrived — size, checksum or download}
        {--dry-run : Print what would be copied and change nothing}';

    protected $description = 'Copy verified backups to off-site storage';

    public function handle(ErrorAlerter $alerter): int
    {
        $from = (string) $this->option('from');
        $to = (string) ($this->option('to') ?: config('backup.offsite.disk'));

        if (! $this->configured($to)) {
            $this->components->warn("Off-site backup is not configured — disk '{$to}' names no bucket. Skipping.");

            // Deliberately success: an install that has not set this up must
            // not fail its nightly cron every night to say so.
            return self::SUCCESS;
        }

        try {
            $copied = $this->copy($from, $to);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());
            $alerter->report(new BackupFailed('Off-site backup sync failed: '.$e->getMessage(), previous: $e));

            return self::FAILURE;
        }

        if ($copied === null) {
            return self::FAILURE;
        }

        if (! $this->option('dry-run')) {
            $this->prune($to);
        }

        $this->newLine();
        $this->components->info($copied === 0
            ? 'Off-site copy already current.'
            : 'Off-site copy complete: '.$copied.' artifact(s).');

        return self::SUCCESS;
    }

    /** Off-site is configured when the destination disk names a bucket. */
    private function configured(string $disk): bool
    {
        return filled(config("filesystems.disks.{$disk}.bucket"));
    }

    /**
     * @return int|null artifacts uploaded, or null if any upload failed
     */
    private function copy(string $from, string $to): ?int
    {
        $local = Storage::disk($from);
        $remote = Storage::disk($to);
        $prefix = (string) config('backup.offsite.prefix');
        $failed = false;
        $copied = 0;

        foreach (['database', 'files'] as $folder) {
            foreach ($local->files($folder) as $file) {
                $key = $prefix === '' ? $file : $prefix.'/'.$file;
                $size = $local->size($file);

                if ($this->alreadyThere($remote, $key, $size)) {
                    $this->components->twoColumnDetail('<fg=gray>skip</> '.$key, 'already off-site');

                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->components->twoColumnDetail('<fg=yellow>would copy</> '.$key, $this->human($size));
                    $copied++;

                    continue;
                }

                if (! $this->upload($local, $remote, $file, $key, $size)) {
                    $failed = true;

                    continue;
                }

                $copied++;
            }
        }

        return $failed ? null : $copied;
    }

    private function alreadyThere($remote, string $key, int $size): bool
    {
        return $remote->exists($key) && $remote->size($key) === $size;
    }

    private function upload($local, $remote, string $file, string $key, int $size): bool
    {
        $stream = $local->readStream($file);

        if ($stream === false || $stream === null) {
            $this->components->error($key.' — cannot read the local artifact.');

            return false;
        }

        try {
            $remote->writeStream($key, $stream);
        } finally {
            // writeStream closes the handle on some adapters and not others.
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $problem = $this->verify($local, $remote, $file, $key, $size);

        if ($problem !== null) {
            // A copy that cannot be proved is worse than none: it is the one
            // that gets relied on. Take it back off the remote.
            $remote->delete($key);
            $this->components->error($key.' — '.$problem.'. Removed from the remote.');

            return false;
        }

        $this->components->twoColumnDetail('<fg=green>copied</> '.$key, $this->human($size));

        return true;
    }

    /**
     * Prove the object that arrived is the artifact that left.
     *
     * Three levels, because they cost wildly different amounts and the honest
     * one is not always affordable:
     *
     * - `size` catches the truncated upload, which is the realistic failure.
     * - `checksum` compares S3's ETag, which **is** the MD5 of the body for a
     *   single-part upload. Large objects go up multipart and their ETag is a
     *   hash of part hashes with a `-parts` suffix, which cannot be compared
     *   to anything computed locally — those fall back to size, and say so.
     * - `download` streams the object back and hashes it. The only check that
     *   proves the bytes, and it costs the bandwidth of the whole archive.
     */
    private function verify($local, $remote, string $file, string $key, int $size): ?string
    {
        $remoteSize = $remote->size($key);

        if ($remoteSize !== $size) {
            return 'uploaded '.$size.' bytes but the remote holds '.$remoteSize;
        }

        $mode = (string) $this->option('verify');

        if ($mode === 'size') {
            return null;
        }

        if ($mode === 'download') {
            return $this->verifyByDownload($local, $remote, $file, $key);
        }

        $etag = trim((string) $remote->checksum($key), '"');

        if ($etag === '' || str_contains($etag, '-')) {
            $this->components->twoColumnDetail(
                '<fg=gray>note</> '.$key,
                'multipart ETag, verified by size only',
            );

            return null;
        }

        $md5 = md5_file($local->path($file));

        return hash_equals($etag, (string) $md5)
            ? null
            : 'checksum mismatch: local '.$md5.', remote '.$etag;
    }

    private function verifyByDownload($local, $remote, string $file, string $key): ?string
    {
        $stream = $remote->readStream($key);

        if ($stream === false || $stream === null) {
            return 'the remote object cannot be read back';
        }

        $context = hash_init('sha256');

        while (! feof($stream)) {
            hash_update($context, (string) fread($stream, 1024 * 1024));
        }

        fclose($stream);

        $remoteHash = hash_final($context);
        $localHash = hash_file('sha256', $local->path($file));

        return hash_equals($remoteHash, (string) $localHash)
            ? null
            : 'downloaded copy does not match: local '.$localHash.', remote '.$remoteHash;
    }

    /**
     * Remote retention, on its own window.
     *
     * Same rule as the local prune and for the same reason: never delete the
     * newest of each kind, so a `--keep` short enough to catch tonight's copy
     * cannot leave the bucket empty.
     */
    private function prune(string $disk): void
    {
        $days = (int) ($this->option('keep') ?? config('backup.offsite.keep_days'));

        if ($days <= 0) {
            return;
        }

        $remote = Storage::disk($disk);
        $prefix = (string) config('backup.offsite.prefix');
        $cutoff = Carbon::now()->subDays($days)->getTimestamp();
        $removed = 0;

        foreach (['database', 'files'] as $folder) {
            $path = $prefix === '' ? $folder : $prefix.'/'.$folder;

            $files = collect($remote->files($path))
                ->sortByDesc(fn (string $f) => $remote->lastModified($f))
                ->values();

            foreach ($files->skip(1) as $f) {
                if ($remote->lastModified($f) < $cutoff) {
                    $remote->delete($f);
                    $removed++;
                }
            }
        }

        if ($removed > 0) {
            $this->components->twoColumnDetail(
                'pruned off-site (older than '.$days.' days)',
                $removed.' object(s)',
            );
        }
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
