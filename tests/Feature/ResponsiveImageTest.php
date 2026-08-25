<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\GalleryImage;
use App\Models\Media;
use App\Services\ArticleQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 6: the WebP ladder reaching the markup.
 *
 * ImageService has always written 320/640/960/1600 derivatives and
 * Media::srcset() has always been able to describe them — nothing rendered
 * them. These tests pin the wiring end to end.
 */
class ResponsiveImageTest extends TestCase
{
    use RefreshDatabase;

    private function publishedArticleWithImage(?Media $media = null): Article
    {
        $article = Article::factory()->create([
            'status' => ArticleStatus::Published,
            'published_at' => now()->subHour(),
            'image_id' => $media?->id,
        ]);

        // Re-fetch through the real listing query so the test exercises the
        // same columns and eager loads production uses.
        return ArticleQuery::cards()->whereKey($article->id)->firstOrFail();
    }

    public function test_srcset_lists_every_width_rung_and_excludes_the_thumbnail(): void
    {
        $media = Media::factory()->create();

        $srcset = $media->srcset();

        foreach ([320, 640, 768, 960, 1600] as $w) {
            $this->assertStringContainsString("-w{$w}.webp {$w}w", $srcset);
        }

        // `thumb` is an admin-grid size, not a rendering width. If the key
        // filter ever loosens, the browser would be offered a 200px candidate
        // for a full-bleed hero.
        $this->assertStringNotContainsString('thumb', $srcset);
    }

    public function test_srcset_reflects_only_the_rungs_that_were_generated(): void
    {
        // ImageService never upscales, so a small source has no upper rungs and
        // must not claim them.
        $media = Media::factory()->small()->create();

        $srcset = $media->srcset();

        $this->assertStringContainsString('320w', $srcset);
        $this->assertStringNotContainsString('768w', $srcset);
        $this->assertStringNotContainsString('1600w', $srcset);
    }

    public function test_rungs_for_reports_the_ladder_a_source_of_a_given_width_earns(): void
    {
        $service = app(\App\Services\ImageService::class);

        $this->assertSame(
            ['w320', 'w640', 'w768', 'w960', 'w1600'],
            $service->rungsFor(2400),
        );

        // Never upscale — except the first rung, which is the floor every
        // source gets so that a tiny image still has one candidate.
        $this->assertSame(['w320', 'w640'], $service->rungsFor(700));
        $this->assertSame(['w320'], $service->rungsFor(200));

        // Unknown source width: assume it earns everything rather than
        // silently truncating a real photograph's ladder.
        $this->assertSame(['w320', 'w640', 'w768', 'w960', 'w1600'], $service->rungsFor(null));
    }

    public function test_article_exposes_no_srcset_without_a_linked_media(): void
    {
        // The state an imported or hand-entered article is left in: a
        // denormalised `image` path and no media row. MediaSeeder no longer
        // leaves the demo database this way, but the factory default still
        // does, and the accessor has to cope.
        $article = $this->publishedArticleWithImage();

        $this->assertNull($article->image_srcset);
        $this->assertSame([null, null], $article->image_dimensions);
    }

    public function test_article_exposes_no_srcset_when_the_media_has_no_conversions(): void
    {
        $media = Media::factory()->withoutConversions()->create();
        $article = $this->publishedArticleWithImage($media);

        // An empty string would render `srcset=""`, which is invalid.
        $this->assertNull($article->image_srcset);
    }

    public function test_listing_query_eager_loads_the_image_so_accessors_do_not_lazy_load(): void
    {
        $media = Media::factory()->create();
        $article = $this->publishedArticleWithImage($media);

        // Strict mode turns a missed eager load into an exception, but the
        // accessor guards with relationLoaded() and would silently return null
        // instead — a regression no error would announce.
        $this->assertTrue($article->relationLoaded('featuredImage'));
        $this->assertNotNull($article->image_srcset);
    }

    public function test_card_renders_srcset_together_with_sizes(): void
    {
        $media = Media::factory()->create();
        $article = $this->publishedArticleWithImage($media);

        $html = $this->blade('<x-article.card :article="$article" variant="standard" />', [
            'article' => $article,
        ]);

        $html->assertSee('srcset=', false);
        $html->assertSee('1600w', false);
        $html->assertSee('sizes=', false);
    }

    public function test_card_omits_sizes_when_there_is_no_srcset(): void
    {
        // `sizes` without `srcset` is inert markup. The two must travel
        // together or not at all.
        $article = $this->publishedArticleWithImage();

        $html = $this->blade('<x-article.card :article="$article" variant="standard" />', [
            'article' => $article,
        ]);

        $html->assertDontSee('srcset=', false);
        $html->assertDontSee('sizes=', false);
    }

    public function test_gallery_image_exposes_srcset_only_when_media_is_loaded(): void
    {
        $media = Media::factory()->create();

        $image = GalleryImage::query()->create([
            'gallery_id' => DB::table('galleries')->insertGetId([
                'title' => 'গ্যালারি',
                'slug' => 'test-gallery',
                'status' => 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'media_id' => $media->id,
            'path' => 'uploads/photo.jpg',
            'position' => 0,
        ]);

        // Unloaded relation: the guard returns null rather than lazy-loading,
        // which strict mode would reject anyway.
        $this->assertNull($image->fresh()->srcset);

        $image->load('media');
        $this->assertStringContainsString('1600w', $image->srcset);
    }

    public function test_article_hero_renders_srcset_and_reserves_its_box(): void
    {
        $media = Media::factory()->create();
        $article = $this->publishedArticleWithImage($media);

        $response = $this->get($article->url);

        $response->assertOk();
        $response->assertSee('srcset=', false);
        // width/height are what keep the LCP image from shifting the page.
        $response->assertSee('width="2400"', false);
        $response->assertSee('height="1350"', false);
    }
}
