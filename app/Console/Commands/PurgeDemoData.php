<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Media;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdService;
use App\Services\HomepageService;
use App\Services\ImageService;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Takes the demo content and the demo logins out, and leaves a working shell.
 *
 * The seeded database is a sales pitch: 374 invented stories, 107 invented
 * comments, faker bylines with `@example.com` addresses, and three logins whose
 * password is the word "password". None of it may survive first contact with
 * the public internet.
 *
 * What is *kept* is the part a newsroom would otherwise have to rebuild by
 * hand: the category tree, the homepage layout, the settings, and the six
 * static pages. The result is an empty newspaper, not a blank database.
 *
 * Not the same thing as `migrate:fresh --seed`, which would take the taxonomy
 * and the layout with it — and not a substitute for it either, since this
 * deliberately leaves the schema and the structure alone.
 *
 * Destructive and unrecoverable. It prompts unless given --force, and --dry-run
 * prints the whole plan without touching anything.
 */
class PurgeDemoData extends Command
{
    use ConfirmableTrait;

    /**
     * The logins `UserSeeder` creates. These go even when one of them is an
     * admin — a known address with a known password is the thing being purged.
     */
    private const DEMO_LOGINS = [
        'admin@newspaper.test',
        'editor@newspaper.test',
        'reader@newspaper.test',
    ];

    /**
     * Emptied outright, in dependency order.
     *
     * Nearly all of this would cascade from `articles` and `users` on its own.
     * Naming every table anyway means a table added later shows up as one this
     * command does not know about, rather than as rows that quietly survive.
     */
    private const PURGE = [
        // Hung off articles
        'article_related', 'article_tag', 'article_topic', 'article_category',
        'comment_likes', 'comments', 'reactions', 'bookmarks', 'reading_history',
        'live_entries', 'gallery_images', 'galleries',
        // Standalone content
        'epaper_pages', 'epapers',
        'poll_votes', 'poll_options', 'polls',
        'articles', 'tags', 'topics', 'media', 'ads',
        'newsletter_subscribers', 'redirects',
        // Anything tied to a browser, a session or a queue run. A push
        // subscription made while testing is a real browser that would
        // otherwise receive the first real breaking alert.
        'push_subscriptions',
        'social_accounts', 'sessions', 'password_reset_tokens',
        'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks',
    ];

    /**
     * Columns holding a file path by name rather than by `media_id`. Most of
     * these modules predate the media library; the files are still ours to
     * remove when the row goes.
     */
    private const FILE_COLUMNS = [
        'articles' => ['image'],
        'topics' => ['image'],
        'live_entries' => ['image'],
        'galleries' => ['cover'],
        'gallery_images' => ['path'],
        'epapers' => ['pdf', 'cover'],
        'epaper_pages' => ['image', 'pdf'],
        'ads' => ['asset'],
    ];

    /** Kept tables carrying a denormalised count of something being deleted. */
    private const COUNTERS = [
        'categories' => 'articles_count',
        'users' => 'articles_count',
    ];

    protected $signature = 'demo:purge
        {--dry-run : Print the plan and change nothing}
        {--keep= : Comma-separated emails to preserve beyond the admins}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Delete the seeded demo content and demo logins, keeping taxonomy, layout, settings and pages';

    public function handle(ImageService $images): int
    {
        $keepEmails = $this->keepEmails();
        $doomedUsers = $this->doomedUsers($keepEmails);
        $keptUsers = User::query()->whereNotIn('id', $doomedUsers)->count();

        $this->plan($doomedUsers, $keptUsers);

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->components->info('Dry run — nothing was deleted.');

            return self::SUCCESS;
        }

        // Deleting every account leaves nobody who can log in and no way to
        // make one short of tinker. Refuse rather than hand back a site its
        // owner is locked out of.
        if ($keptUsers === 0) {
            $this->newLine();
            $this->components->error(
                'This would delete every user and leave no way to sign in. '
                .'Promote an account to admin, or name one with --keep=you@example.com.'
            );

            return self::FAILURE;
        }

        if (! $this->confirmToProceed('Purging demo content is not reversible.', fn () => true)) {
            return self::FAILURE;
        }

        // Collected before the rows go: afterwards there is nothing left to
        // read the paths off.
        $files = $this->filePaths($doomedUsers);

        // Media first, and through ImageService: a row's derivatives live in
        // its `conversions` column, so deleting the row before reading it
        // strands the whole ladder on disk.
        $filesRemoved = $this->purgeMedia($images);

        $deleted = $this->purgeTables($doomedUsers);
        $filesRemoved += $this->purgeFiles($files);
        $this->resetCounters();
        $this->flushCaches();

        $this->newLine();
        $this->components->info('Purged '.array_sum($deleted).' rows and '.$filesRemoved.' files.');
        $this->reportOrphans();

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>Next</>', '');
        $this->components->twoColumnDetail('  Sign in as', User::query()->orderBy('id')->value('email') ?? '—');
        $this->components->twoColumnDetail('  Still holding sample text', 'settings (imprint), the six static pages');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function keepEmails(): array
    {
        return collect(explode(',', (string) $this->option('keep')))
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Everyone except the admins, plus any address named with --keep. The
     * seeded logins go regardless of role.
     *
     * @param  list<string>  $keepEmails
     * @return list<int>
     */
    private function doomedUsers(array $keepEmails): array
    {
        return User::query()
            ->where(function ($query) {
                $query->where('role', '!=', UserRole::Admin->value)
                    ->orWhereIn('email', self::DEMO_LOGINS);
            })
            ->whereNotIn('email', $keepEmails)
            ->pluck('id')
            ->all();
    }

    /** @param  list<int>  $doomedUsers */
    private function plan(array $doomedUsers, int $keptUsers): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=red>To delete</>', '');

        foreach (self::PURGE as $table) {
            if (! Schema::hasTable($table)) {
                $this->components->twoColumnDetail("  {$table}", '<fg=yellow>no such table</>');

                continue;
            }

            $count = DB::table($table)->count();

            if ($count > 0) {
                $this->components->twoColumnDetail("  {$table}", (string) $count);
            }
        }

        $this->components->twoColumnDetail('  users', (string) count($doomedUsers));

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>To keep</>', '');

        foreach (['categories', 'home_blocks', 'settings', 'pages'] as $table) {
            $this->components->twoColumnDetail("  {$table}", (string) DB::table($table)->count());
        }

        $this->components->twoColumnDetail('  users', (string) $keptUsers);

        foreach (User::query()->whereNotIn('id', $doomedUsers)->get(['email', 'role']) as $user) {
            $this->components->twoColumnDetail('    '.$user->email, $user->role->value);
        }
    }

    /**
     * Every file path the doomed rows point at, by bare column.
     *
     * @param  list<int>  $doomedUsers
     * @return list<string>
     */
    private function filePaths(array $doomedUsers): array
    {
        $paths = [];

        foreach (self::FILE_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                $paths = array_merge($paths, DB::table($table)->whereNotNull($column)->pluck($column)->all());
            }
        }

        // Only the avatars of accounts actually going away.
        if ($doomedUsers !== []) {
            $paths = array_merge($paths, DB::table('users')
                ->whereIn('id', $doomedUsers)->whereNotNull('avatar')->pluck('avatar')->all());
        }

        // An `http` value is someone else's file — an OAuth avatar, usually.
        return array_values(array_unique(array_filter(
            $paths,
            fn ($path) => is_string($path) && $path !== '' && ! str_starts_with($path, 'http'),
        )));
    }

    /**
     * @param  list<int>  $doomedUsers
     * @return array<string, int>
     */
    private function purgeTables(array $doomedUsers): array
    {
        $deleted = [];

        // The FK graph cascades cleanly, but a purge is not the place to
        // discover an exception: delete in dependency order with the checks
        // off, then let them back on.
        Schema::disableForeignKeyConstraints();

        try {
            foreach (self::PURGE as $table) {
                if (Schema::hasTable($table)) {
                    $deleted[$table] = DB::table($table)->delete();
                }
            }

            if ($doomedUsers !== []) {
                $deleted['users'] = DB::table('users')->whereIn('id', $doomedUsers)->delete();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return $deleted;
    }

    /**
     * Every media row, with its original and its whole derivative ladder.
     *
     * `ImageService::delete()` is the only thing that knows a row owns more
     * than one file, and it needs the row to find out.
     */
    private function purgeMedia(ImageService $images): int
    {
        $removed = 0;

        Media::query()->orderBy('id')->chunkById(100, function ($rows) use ($images, &$removed): void {
            foreach ($rows as $media) {
                $removed += 1 + count(array_filter((array) $media->conversions, 'is_string'));
                $images->delete($media);
            }
        });

        return $removed;
    }

    /**
     * Files named by a bare path column. Collected before the rows went; by
     * now there is nothing left pointing at them.
     *
     * @param  list<string>  $paths
     */
    private function purgeFiles(array $paths): int
    {
        $disk = Storage::disk('public');
        $removed = 0;

        foreach ($paths as $path) {
            if ($disk->exists($path) && $disk->delete($path)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Categories and the surviving admins keep a count of articles that no
     * longer exist. Query builder, because these are guarded attributes.
     */
    private function resetCounters(): void
    {
        foreach (self::COUNTERS as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                DB::table($table)->update([$column => 0]);
            }
        }
    }

    /**
     * Cached payloads hold serialised models that no longer have rows. Leaving
     * them is not merely stale — `config/cache.php` deserialises them on the
     * next request.
     */
    private function flushCaches(): void
    {
        HomepageService::flush();
        AdService::flush();
        Setting::flush();
        Cache::forget('layout.categories');
        Cache::forget('layout.trending');
    }

    /**
     * Files under `uploads/` that no row pointed at. Reported rather than
     * deleted: this command knows what the demo data referenced, and a blind
     * sweep of the directory is a different and much less careful promise.
     */
    private function reportOrphans(): void
    {
        $disk = Storage::disk('public');

        if (! $disk->exists('uploads')) {
            return;
        }

        $left = collect($disk->allFiles('uploads'));

        if ($left->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->warn(
            $left->count().' file(s) remain under storage/app/public/uploads that no row referenced. '
            .'Review and remove them by hand if they are demo leftovers.'
        );
    }
}
