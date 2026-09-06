<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Article;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Seeder;

class ContentSeeder extends Seeder
{
    /**
     * [Bangla, English]. The English name is what `/en/tag/{slug}` prints.
     *
     * The **slug does not change between editions** — it is derived from the
     * Bangla name and stays Bangla, so `/en/tag/ক্রিকেট` is an English page at
     * a Bangla address. A second slug column would give a tag two identities
     * and make `Tag::articles()` choose one; a percent-encoded path segment is
     * something browsers have handled correctly for twenty years, and the
     * Bangla site already ships them everywhere.
     */
    private const TAGS = [
        ['নির্বাচন', 'Election'], ['বাজেট', 'Budget'], ['ডেঙ্গু', 'Dengue'],
        ['শিক্ষাক্রম', 'Curriculum'], ['রেমিট্যান্স', 'Remittance'], ['বিশ্বকাপ', 'World Cup'],
        ['জলবায়ু', 'Climate'], ['যানজট', 'Traffic'], ['মূল্যস্ফীতি', 'Inflation'],
        ['সংস্কার', 'Reform'], ['রপ্তানি', 'Exports'], ['ক্রিকেট', 'Cricket'],
        ['ফুটবল', 'Football'], ['চলচ্চিত্র', 'Cinema'], ['প্রযুক্তি', 'Technology'],
        ['স্বাস্থ্যসেবা', 'Healthcare'], ['কৃষি', 'Agriculture'], ['জ্বালানি', 'Energy'],
    ];

    /** [Bangla, slug, trending, English]. */
    private const TOPICS = [
        ['জাতীয় নির্বাচন ২০২৬', 'national-election-2026', true, 'National Election 2026'],
        ['বিশ্বকাপ ২০২৬', 'world-cup-2026', true, 'World Cup 2026'],
        ['ডেঙ্গু পরিস্থিতি', 'dengue-situation', true, 'The Dengue Outbreak'],
        ['অর্থনৈতিক সংস্কার', 'economic-reform', true, 'Economic Reform'],
        ['জলবায়ু সম্মেলন', 'climate-summit', false, 'The Climate Summit'],
    ];

    public function run(): void
    {
        $reporters = User::where('role', UserRole::Reporter)->get();
        $readers = User::where('role', UserRole::Reader)->get();
        $categories = Category::with('parent')->whereNotNull('parent_id')->get();
        $roots = Category::whereNull('parent_id')->get()->keyBy('slug');

        // `updateOrCreate` on the name rather than `firstOrCreate`, so a
        // re-seed fills in `name_en` on tags that already existed from before
        // the column did. It writes nothing else.
        $tags = collect(self::TAGS)->map(
            fn ($t) => Tag::updateOrCreate(['name' => $t[0]], ['name_en' => $t[1]])
        );

        $topics = collect(self::TOPICS)->map(
            fn ($t) => Topic::updateOrCreate(
                ['slug' => $t[1]],
                ['name' => $t[0], 'name_en' => $t[3], 'is_trending' => $t[2], 'is_active' => true],
            )
        );

        $this->command->info('Seeding articles…');

        // ── Ordinary stories across every leaf category ──────────────────
        foreach ($categories as $category) {
            $count = random_int(6, 12);

            $articles = Article::factory()
                ->count($count)
                ->inCategory($category)
                ->recycle($reporters)
                ->create();

            $this->attachTaxonomy($articles, $tags, $topics);
        }

        // ── Front-page leads ────────────────────────────────────────────
        Article::factory()->count(5)->lead()
            ->recycle($reporters)
            ->recycle($categories)
            ->create();

        // ── Breaking, for the ticker ─────────────────────────────────────
        Article::factory()->count(4)->breaking()
            ->recycle($reporters)
            ->recycle($categories)
            ->create();

        // ── Video hub ────────────────────────────────────────────────────
        if ($videoCat = $roots->get('video')) {
            Article::factory()->count(12)->video()
                ->recycle($reporters)
                ->create(['category_id' => $videoCat->id]);
        }

        // ── Opinion columns ──────────────────────────────────────────────
        if ($opinionCat = $roots->get('opinion')) {
            Article::factory()->count(10)->opinion()
                ->recycle($reporters)
                ->create(['category_id' => $opinionCat->id]);
        }

        $this->command->info('Seeding comments…');

        // Comments on a slice of recent stories, some still pending so the
        // moderation queue has something in it on first login.
        Article::published()->latest('published_at')->take(30)->get()
            ->each(function (Article $article) use ($readers) {
                Comment::factory()
                    ->count(random_int(0, 6))
                    ->recycle($readers)
                    ->create(['article_id' => $article->id]);

                if (random_int(1, 3) === 1) {
                    Comment::factory()->pending()
                        ->recycle($readers)
                        ->create(['article_id' => $article->id]);
                }
            });

        $this->syncCounters();
    }

    private function attachTaxonomy($articles, $tags, $topics): void
    {
        foreach ($articles as $article) {
            $article->tags()->sync($tags->random(random_int(1, 4))->pluck('id'));

            // Only ~1 in 4 stories belongs to a running-story cluster.
            if (random_int(1, 4) === 1) {
                $article->topics()->sync([$topics->random()->id]);
            }
        }
    }

    /**
     * The counters are maintained by model events at runtime, but a seed run
     * writes plenty of rows past them — pivot syncs and query-builder inserts
     * fire nothing — so reconcile once at the end.
     *
     * Calls the command rather than repeating its UPDATEs. Two definitions of
     * a correct count is how the thing that fixes drift comes to disagree
     * about what drift is.
     */
    private function syncCounters(): void
    {
        $this->command->call('counters:recompute');
    }
}
