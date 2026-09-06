<?php

namespace Tests\Feature;

use App\Enums\HomeBlockType;
use App\Models\Article;
use App\Models\Category;
use App\Models\HomeBlock;
use App\Models\Topic;
use App\Support\Contrast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The section and topic labels, printed in an editor-picked colour.
 *
 * Four templates print a category or topic name in that taxonomy's own
 * colour, and the colour is an `<input type="color">` in the admin. On white
 * two of the eighteen seeded category colours fail WCAG AA; **on the dark
 * surface sixteen of the eighteen do**, which is the part nobody had looked
 * at — the light theme was the visible half of a defect that was mostly in
 * the other one.
 *
 * The server cannot know which theme the reader is in: it is a class on
 * `<html>` that Alpine restores from `localStorage`. So each label carries
 * both answers as custom properties and `.section-label` picks, and what is
 * asserted here is that **both** of them clear AA against their own surface.
 *
 * Every test asserts a label was rendered at all before asserting anything
 * about its colour. An `assertDontSee`-shaped check against a page that
 * renders no labels is the failure this repository has shipped before.
 */
class SectionLabelTest extends TestCase
{
    use RefreshDatabase;

    /** `#DB6B00`: 3.43:1 on white, and one of the two that pass on dark. */
    private const FAILS_ON_LIGHT = '#DB6B00';

    /** `#6D28D9`: 7.10:1 on white, and 2.46:1 on the dark surface. */
    private const FAILS_ON_DARK = '#6D28D9';

    // ── The four templates ───────────────────────────────────────────────

    public function test_a_card_label_is_legible_on_both_themes(): void
    {
        $category = Category::factory()->create([
            'name' => 'জীবনযাপন', 'color' => self::FAILS_ON_LIGHT,
        ]);

        // Three, not one: the category page's lead card is deliberately
        // rendered with `:show-category="false"` — the label would repeat the
        // heading directly above it — so a single article renders no label at
        // all and the control assertion below is what says so.
        Article::factory()->count(3)->for($category)->create(['kicker' => null]);

        $this->assertLabelsAreLegible(
            $this->get(route('category.show', $category))->assertOk()->getContent(),
            'the category page card', 'জীবনযাপন',
        );
    }

    public function test_an_article_kicker_is_legible_on_both_themes(): void
    {
        $article = Article::factory()
            ->for(Category::factory()->create(['color' => self::FAILS_ON_DARK]))
            ->create(['kicker' => 'বিশ্লেষণ']);

        $this->assertLabelsAreLegible(
            $this->get($article->url)->assertOk()->getContent(),
            'the article kicker', 'বিশ্লেষণ',
        );
    }

    public function test_a_topic_page_label_is_legible_on_both_themes(): void
    {
        $topic = Topic::factory()->create(['color' => self::FAILS_ON_DARK]);

        Article::factory()->hasAttached($topic)->create();

        $this->assertLabelsAreLegible(
            $this->get(route('topic.show', $topic))->assertOk()->getContent(),
            'the topic page', 'বিশেষ আয়োজন',
        );
    }

    public function test_a_topic_cluster_label_is_legible_on_both_themes(): void
    {
        $topic = Topic::factory()->create(['color' => self::FAILS_ON_DARK]);

        Article::factory()->count(3)->hasAttached($topic)->create();

        HomeBlock::create([
            'type' => HomeBlockType::TopicCluster,
            'limit' => 3,
            'column' => 'main',
            'position' => 0,
            'topic_id' => $topic->id,
            'is_active' => true,
        ]);

        $this->assertLabelsAreLegible(
            $this->get('/')->assertOk()->getContent(),
            'the homepage topic cluster', 'বিশেষ আয়োজন',
        );
    }

    // ── The raw colour must not survive anywhere ─────────────────────────

    /**
     * The seeded colour reaching a `color:` declaration is the defect itself.
     * Checked separately from the ratio because a template could compute one
     * property correctly and leave the old declaration beside it, where the
     * later one silently wins.
     *
     * **Anchored, because `color:` is a substring of `border-color:`.** The
     * first version of this assertion was `assertStringNotContainsString`
     * and it failed against a page that was already correct — the category
     * page draws two rules in the section's colour, which is exactly what it
     * should do. That is the "assertion mechanism that fails for the wrong
     * reason" shape `CLAUDE.md` warns about, and it cost the same time as a
     * real bug while making working code look broken.
     */
    public function test_the_editors_raw_colour_is_not_printed_as_text(): void
    {
        $category = Category::factory()->create(['color' => self::FAILS_ON_LIGHT]);
        Article::factory()->count(3)->for($category)->create(['kicker' => null]);

        $html = $this->get(route('category.show', $category))->assertOk()->getContent();

        $this->assertStringContainsString('section-label', $html, 'No label rendered.');

        $this->assertDoesNotMatchRegularExpression(
            '/(?<![-\w])color:\s*'.self::FAILS_ON_LIGHT.'/i', $html,
            'The editor\'s raw colour is still being printed as text.',
        );
    }

    /**
     * The other half of that: a colour used as a **rule** is not text, AA
     * does not apply to it, and it must keep the colour the editor picked.
     * A fix that swept every occurrence would repaint the category page's
     * underline and the block headers for no reason, and nothing else here
     * would notice.
     */
    public function test_a_colour_used_as_a_border_is_left_exactly_as_it_is(): void
    {
        $category = Category::factory()->create(['color' => self::FAILS_ON_LIGHT]);
        Article::factory()->count(3)->for($category)->create(['kicker' => null]);

        $html = $this->get(route('category.show', $category))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/border-(?:bottom-)?color:\s*'.self::FAILS_ON_LIGHT.'/i', $html,
            'The section rule lost the colour the editor chose.',
        );
    }

    // ── The stylesheet has to agree ──────────────────────────────────────

    /**
     * `Contrast::SURFACE_LIGHT` and `SURFACE_DARK` are a copy of
     * `--color-surface` from `app.css`. Nothing links the two files, so a
     * theme edit would leave every label computed against a background that
     * no longer exists — and it would look completely fine, because the
     * labels would still render in a colour.
     */
    public function test_the_surfaces_match_the_stylesheet(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // The dark block redefines the token, so the *last* declaration in
        // the file is the dark one and the first is the light one.
        preg_match_all('/--color-surface:\s*(#[0-9A-Fa-f]{6})\s*;/', $css, $found);

        $this->assertCount(2, $found[1],
            'Expected --color-surface to be declared once per theme in app.css.');

        $this->assertSame(strtoupper($found[1][0]), strtoupper(Contrast::SURFACE_LIGHT));
        $this->assertSame(strtoupper($found[1][1]), strtoupper(Contrast::SURFACE_DARK));
    }

    /**
     * The stylesheet is the other half of the mechanism: the properties are
     * inert unless something reads them, and reads the dark one under `.dark`.
     */
    public function test_the_stylesheet_reads_both_properties(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/\.section-label\s*\{[^}]*--label-light/', $css);
        $this->assertMatchesRegularExpression('/\.dark\s+\.section-label\s*\{[^}]*--label-dark/', $css);
    }

    // ── The helper ───────────────────────────────────────────────────────

    public function test_a_missing_colour_falls_back_rather_than_emitting_nothing(): void
    {
        $style = Contrast::labelStyle(null);

        $this->assertStringContainsString('--label-light:#C8102E', $style);
        $this->assertStringContainsString('--label-dark:', $style);
    }

    // ── The assertion the four page tests share ──────────────────────────

    /**
     * Every `.section-label` **whose text is `$says`** carries both
     * properties, and each clears AA against the surface it is for.
     *
     * **The text is what makes this test about one template.** Without it,
     * reverting `site/topic.blade.php` to the raw colour left this passing:
     * the topic page also lists articles, every card carries a label of its
     * own, and those satisfied a control that was only counting labels. The
     * test was green against the defect it existed to catch. Anchoring on the
     * label's own words is what ties each case to the file it names.
     */
    private function assertLabelsAreLegible(string $html, string $what, string $says): void
    {
        preg_match_all(
            '/class="section-label[^"]*"\s+style="--label-light:(#[0-9A-Fa-f]{6});--label-dark:(#[0-9A-Fa-f]{6})"\s*>\s*'
                .preg_quote($says, '/').'/su',
            $html, $labels, PREG_SET_ORDER,
        );

        // The control. Without it every loop below is vacuously true.
        $this->assertNotEmpty($labels, "No section label reading “{$says}” rendered on {$what}.");

        foreach ($labels as [, $light, $dark]) {
            $this->assertGreaterThanOrEqual(
                Contrast::AA, $ratio = Contrast::ratio($light, Contrast::SURFACE_LIGHT),
                "On {$what} the light label is {$light}, {$ratio}:1 on the light surface.",
            );

            $this->assertGreaterThanOrEqual(
                Contrast::AA, $ratio = Contrast::ratio($dark, Contrast::SURFACE_DARK),
                "On {$what} the dark label is {$dark}, {$ratio}:1 on the dark surface.",
            );
        }
    }
}
