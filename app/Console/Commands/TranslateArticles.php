<?php

namespace App\Console\Commands;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Services\HomepageService;
use App\Support\Locale;
use Database\Seeders\Support\EnglishContent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills the English edition with counterparts of existing Bangla stories.
 *
 * The edition is a mechanism, and a mechanism with no content is invisible:
 * `/en` renders, the switcher never appears on any article, `hreflang` is
 * emitted nowhere, and every locale-scoping bug looks exactly like a working
 * site. This is what makes it demonstrable — on the development box, and in
 * the tests that need a bilingual pair without building one by hand.
 *
 * Three properties it has on purpose, and each of them is the difference
 * between a demo tool and a data-loss tool:
 *
 * - **Idempotent.** A story that already has a counterpart in any state,
 *   draft included, is skipped. Running it twice does not produce two English
 *   articles for one Bangla one, which `counterpart()` could not choose
 *   between.
 * - **Deterministic.** The English text is derived from the source article's
 *   id, so the same Bangla story always yields the same English one — across
 *   a re-seed, and between two boxes. `EpaperSeeder` and `photos:import` make
 *   the same promise for the same reason.
 * - **It only ever inserts.** It never edits or deletes an existing article,
 *   in either edition, so it cannot damage anything an editor has written.
 *
 * It is **not a translator**. The English copy has nothing to do with the
 * Bangla copy beyond the section, the byline and the photograph — see
 * `EnglishContent`. Nothing here should ever be pointed at a real newsroom's
 * archive.
 */
class TranslateArticles extends Command
{
    protected $signature = 'articles:translate
                            {--count=40 : How many stories to give a counterpart}
                            {--draft : Leave the counterparts unpublished}
                            {--dry-run : List what would be created and change nothing}';

    protected $description = 'Create English counterparts of published Bangla articles (demo content)';

    public function handle(): int
    {
        $count = max(1, (int) $this->option('count'));
        $dry = (bool) $this->option('dry-run');

        // Newest first, because a demo edition wants recent stories on its
        // front page — but keyed and reported by id, which does not move.
        $sources = Article::query()
            ->published()
            ->locale(Locale::DEFAULT)
            ->whereNull('translation_of')
            ->whereDoesntHave('translations', fn ($q) => $q->where('locale', Locale::ALTERNATE))
            ->with(['tags:id', 'topics:id'])
            ->latest('published_at')
            ->limit($count)
            ->get();

        if ($sources->isEmpty()) {
            $this->info('Every published Bangla story already has an English counterpart.');

            return self::SUCCESS;
        }

        $this->line(($dry ? 'Would create ' : 'Creating ').$sources->count().' English counterpart(s).');

        foreach ($sources as $source) {
            $headline = EnglishContent::headline($source->id);

            $this->line(sprintf('  #%-6d %s', $source->id, $headline));

            if ($dry) {
                continue;
            }

            DB::transaction(function () use ($source, $headline) {
                $copy = Article::create([
                    'category_id' => $source->category_id,
                    'author_id' => $source->author_id,
                    'editor_id' => $source->editor_id,
                    'title' => $headline,
                    'excerpt' => EnglishContent::excerpt($source->id),
                    'body' => EnglishContent::body($source->id),
                    'type' => $source->type,
                    'status' => $this->option('draft') ? ArticleStatus::Draft : ArticleStatus::Published,
                    'image_id' => $source->image_id,
                    'image' => $source->image,
                    'image_caption' => EnglishContent::sentence($source->id, 77, 8),
                    'image_credit' => 'Photo: collected',
                    'allow_comments' => $source->allow_comments,
                    'dateline' => EnglishContent::dateline($source->id),
                    'locale' => Locale::ALTERNATE,
                    'translation_of' => $source->id,
                ]);

                // `published_at` is guarded, and it has to match the source or
                // the English edition's ordering is the order this command
                // happened to run in rather than the order the news broke.
                if (! $this->option('draft')) {
                    $copy->forceFill(['published_at' => $source->published_at])->save();
                }

                $copy->tags()->sync($source->tags->pluck('id'));
                $copy->topics()->sync($source->topics->pluck('id'));
            });
        }

        if (! $dry) {
            // The counts on `categories` and `users` are maintained by model
            // events, which `Article::create()` fired — but the front page and
            // the feeds are cached, and both now have a second edition to
            // build.
            HomepageService::flush();

            $this->newLine();
            $this->info($sources->count().' English counterpart(s) created.');
        }

        return self::SUCCESS;
    }
}
