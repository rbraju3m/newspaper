<?php

namespace Database\Seeders;

use App\Enums\ArticleType;
use App\Enums\UserRole;
use App\Models\Article;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\User;
use Database\Seeders\Support\BanglaContent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContentSeeder extends Seeder
{
    private const TAGS = [
        'নির্বাচন', 'বাজেট', 'ডেঙ্গু', 'শিক্ষাক্রম', 'রেমিট্যান্স', 'বিশ্বকাপ',
        'জলবায়ু', 'যানজট', 'মূল্যস্ফীতি', 'সংস্কার', 'রপ্তানি', 'ক্রিকেট',
        'ফুটবল', 'চলচ্চিত্র', 'প্রযুক্তি', 'স্বাস্থ্যসেবা', 'কৃষি', 'জ্বালানি',
    ];

    private const TOPICS = [
        ['জাতীয় নির্বাচন ২০২৬', 'national-election-2026', true],
        ['বিশ্বকাপ ২০২৬', 'world-cup-2026', true],
        ['ডেঙ্গু পরিস্থিতি', 'dengue-situation', true],
        ['অর্থনৈতিক সংস্কার', 'economic-reform', true],
        ['জলবায়ু সম্মেলন', 'climate-summit', false],
    ];

    public function run(): void
    {
        $reporters = User::where('role', UserRole::Reporter)->get();
        $readers = User::where('role', UserRole::Reader)->get();
        $categories = Category::with('parent')->whereNotNull('parent_id')->get();
        $roots = Category::whereNull('parent_id')->get()->keyBy('slug');

        $tags = collect(self::TAGS)->map(
            fn ($name) => Tag::firstOrCreate(['name' => $name])
        );

        $topics = collect(self::TOPICS)->map(
            fn ($t) => Topic::updateOrCreate(
                ['slug' => $t[1]],
                ['name' => $t[0], 'is_trending' => $t[2], 'is_active' => true],
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
     * The denormalised counters are maintained incrementally at runtime, but
     * factory-created rows bypass those paths, so recompute once at the end.
     */
    private function syncCounters(): void
    {
        $this->command->info('Recomputing counters…');

        DB::statement('
            UPDATE categories c SET articles_count = (
                SELECT COUNT(*) FROM articles a
                WHERE a.category_id = c.id AND a.status = "published" AND a.deleted_at IS NULL
            )
        ');

        DB::statement('
            UPDATE users u SET articles_count = (
                SELECT COUNT(*) FROM articles a
                WHERE a.author_id = u.id AND a.status = "published" AND a.deleted_at IS NULL
            )
        ');

        DB::statement('
            UPDATE tags t SET articles_count = (
                SELECT COUNT(*) FROM article_tag at WHERE at.tag_id = t.id
            )
        ');

        DB::statement('
            UPDATE topics t SET articles_count = (
                SELECT COUNT(*) FROM article_topic at WHERE at.topic_id = t.id
            )
        ');

        DB::statement('
            UPDATE articles a SET comments_count = (
                SELECT COUNT(*) FROM comments c
                WHERE c.article_id = a.id AND c.status = "approved" AND c.deleted_at IS NULL
            )
        ');
    }
}
