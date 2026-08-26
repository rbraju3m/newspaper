<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `backup:run` — and specifically, whether what it writes is restorable.
 *
 * DatabaseTruncation rather than RefreshDatabase, for the same reason
 * SearchTest uses it: mysqldump opens its own connection. Under
 * RefreshDatabase every row a test creates sits inside an uncommitted
 * transaction, so the dump would faithfully contain the schema and none of the
 * data, and a test asserting "the backup has my article in it" would fail
 * while the command was working perfectly — or worse, pass while it wasn't.
 */
class BackupTest extends TestCase
{
    use DatabaseTruncation;

    private function backupDisk(): string
    {
        // A real directory, not an in-memory fake: mysqldump and tar write
        // through the shell, so the path has to exist on the filesystem.
        Storage::fake('backups');

        return 'backups';
    }

    private function latest(string $folder): ?string
    {
        $files = Storage::disk('backups')->files($folder);

        return $files === [] ? null : $files[0];
    }

    private function contents(string $file): string
    {
        return (string) shell_exec('gzip -cd '.escapeshellarg(Storage::disk('backups')->path($file)));
    }

    public function test_it_writes_a_database_dump_and_an_uploads_archive(): void
    {
        $this->backupDisk();
        Storage::disk('public')->put('uploads/2026/08/demo/a.jpg', 'x');

        $this->artisan('backup:run')->assertSuccessful();

        $this->assertNotNull($this->latest('database'));
        $this->assertNotNull($this->latest('files'));
    }

    /**
     * The assertion this whole file exists for. A dump holding the schema and
     * no rows restores into an empty newspaper, and looks identical from the
     * outside.
     */
    public function test_the_dump_actually_contains_the_rows(): void
    {
        $this->backupDisk();

        $article = Article::factory()->create([
            'category_id' => Category::factory()->create()->id,
            'title' => 'রপ্তানি আয় বাড়াতে বড় সিদ্ধান্ত',
        ]);

        $this->artisan('backup:run --database')->assertSuccessful();

        $sql = $this->contents($this->latest('database'));

        $this->assertStringContainsString('CREATE TABLE `articles`', $sql);
        $this->assertStringContainsString($article->title, $sql, 'the dump has schema but no data');
    }

    /** Bangla must survive the dump, or a restore is a corrupted newspaper. */
    public function test_bangla_survives_the_dump(): void
    {
        $this->backupDisk();

        Article::factory()->create([
            'category_id' => Category::factory()->create()->id,
            'title' => 'জলবায়ু পরিবর্তনের প্রভাব মোকাবিলায় ৳১২,৫০০ কোটি টাকা',
        ]);

        $this->artisan('backup:run --database')->assertSuccessful();

        $sql = $this->contents($this->latest('database'));

        $this->assertStringContainsString('জলবায়ু পরিবর্তনের প্রভাব মোকাবিলায় ৳১২,৫০০ কোটি টাকা', $sql);
        $this->assertStringContainsString('utf8mb4', $sql);
    }

    /**
     * mysqldump writes its completion marker last, and a truncated dump is a
     * *valid* gzip file — `gzip -t` passes it. The marker is the only cheap
     * proof the dump finished.
     */
    public function test_the_finished_dump_carries_its_completion_marker(): void
    {
        $this->backupDisk();

        $this->artisan('backup:run --database')->assertSuccessful();

        $this->assertStringContainsString('Dump completed', $this->contents($this->latest('database')));
    }

    public function test_the_uploads_archive_holds_relative_paths(): void
    {
        $this->backupDisk();
        Storage::disk('public')->put('uploads/2026/08/demo/a.jpg', 'x');

        $this->artisan('backup:run --files')->assertSuccessful();

        $listing = (string) shell_exec(
            'tar -tzf '.escapeshellarg(Storage::disk('backups')->path($this->latest('files')))
        );

        // `uploads/...`, not `/var/www/...` — an absolute path would restore
        // onto the wrong box, or refuse to restore at all.
        $this->assertStringContainsString('uploads/2026/08/demo/a.jpg', $listing);
        $this->assertStringNotContainsString(storage_path(), $listing);
    }

    public function test_the_flags_select_one_half_each(): void
    {
        $this->backupDisk();
        Storage::disk('public')->put('uploads/2026/08/demo/a.jpg', 'x');

        $this->artisan('backup:run --database')->assertSuccessful();
        $this->assertNotNull($this->latest('database'));
        $this->assertNull($this->latest('files'));

        $this->artisan('backup:run --files')->assertSuccessful();
        $this->assertNotNull($this->latest('files'));
    }

    /**
     * `storage:link` symlinks the public disk into the document root, so a
     * dump written there is one guessed filename from being downloaded.
     */
    public function test_it_refuses_to_write_where_the_web_server_can_serve_it(): void
    {
        config(['filesystems.disks.exposed' => [
            'driver' => 'local',
            'root' => storage_path('app/public/backups'),
        ]]);

        $this->artisan('backup:run --disk=exposed')->assertFailed();
    }

    public function test_an_unknown_disk_fails_rather_than_writing_somewhere_else(): void
    {
        $this->artisan('backup:run --disk=nowhere')->assertFailed();
    }

    /** Pruning must never take the last backup, whatever --keep says. */
    public function test_pruning_keeps_the_newest_even_when_it_is_older_than_keep(): void
    {
        $this->backupDisk();

        Storage::disk('backups')->put('database/old.sql.gz', 'x');
        touch(Storage::disk('backups')->path('database/old.sql.gz'), now()->subDays(90)->getTimestamp());

        Storage::disk('backups')->put('database/older.sql.gz', 'x');
        touch(Storage::disk('backups')->path('database/older.sql.gz'), now()->subDays(120)->getTimestamp());

        $this->artisan('backup:run --files --keep=1')->assertSuccessful();

        $remaining = Storage::disk('backups')->files('database');

        $this->assertSame(['database/old.sql.gz'], $remaining, 'the newest must survive any --keep');
    }

    public function test_keep_zero_prunes_nothing(): void
    {
        $this->backupDisk();

        Storage::disk('backups')->put('database/ancient.sql.gz', 'x');
        touch(Storage::disk('backups')->path('database/ancient.sql.gz'), now()->subDays(400)->getTimestamp());

        $this->artisan('backup:run --files --keep=0')->assertSuccessful();

        $this->assertTrue(Storage::disk('backups')->exists('database/ancient.sql.gz'));
    }
}
