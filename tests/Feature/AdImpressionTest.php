<?php

namespace Tests\Feature;

use App\Models\Ad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ad impressions — counted by the browser, not by the server.
 *
 * `ads.impressions` sat at zero for the life of the project while `clicks`
 * was maintained, so every CTR the admin displayed was a division by zero
 * dressed up as 0.0%.
 *
 * The reason it is a beacon rather than a line in the blade is in
 * `AdController::impressions()`: a render is not an impression. What is
 * asserted here is the contract that follows from that — the endpoint counts
 * what it is told, refuses what it should not count, and costs one query
 * however many slots reported.
 */
class AdImpressionTest extends TestCase
{
    use RefreshDatabase;

    private function ad(array $attributes = []): Ad
    {
        return Ad::create([
            'title' => 'ঈদ অফার',
            'position' => 'header',
            'type' => 'image',
            'asset' => 'uploads/ads/creative.jpg',
            'url' => 'https://example.com/offer',
            'is_active' => true,
            'priority' => 1,
            ...$attributes,
        ]);
    }

    private function report(array $ids): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/ads/impressions', ['ids' => $ids]);
    }

    public function test_a_reported_slot_is_counted(): void
    {
        $ad = $this->ad();

        $this->report([$ad->id])->assertOk()->assertJson(['counted' => 1]);

        $this->assertSame(1, (int) $ad->fresh()->impressions);
    }

    public function test_every_slot_on_a_page_is_counted_in_one_query(): void
    {
        $ads = collect(['header', 'sidebar', 'in-article'])
            ->map(fn ($position) => $this->ad(['position' => $position]));

        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            if (str_starts_with(strtolower(trim($query->sql)), 'update')) {
                $writes++;
            }
        });

        $this->report($ads->pluck('id')->all())->assertOk()->assertJson(['counted' => 3]);

        foreach ($ads as $ad) {
            $this->assertSame(1, (int) $ad->fresh()->impressions);
        }

        // The whole point of batching. Three slots must not be three writes on
        // a front page that is otherwise eight queries warm.
        $this->assertSame(1, $writes, 'Three reported slots cost more than one write.');
    }

    /**
     * The same banner scrolled past twice is one impression, and the client is
     * what enforces that — but a beacon that repeats an id must not multiply
     * it either.
     *
     * Worth knowing what this does *not* guard: removing `array_unique()` from
     * the controller leaves it passing, because `where id in (5, 5, 5)` matches
     * the row once and `increment` writes it once. SQL dedupes for us. The call
     * stays because it shortens the `IN` list, not because it is load-bearing,
     * and this pins the outcome rather than the line.
     */
    public function test_a_repeated_id_in_one_beacon_counts_once(): void
    {
        $ad = $this->ad();

        $this->report([$ad->id, $ad->id, $ad->id])->assertOk()->assertJson(['counted' => 1]);

        $this->assertSame(1, (int) $ad->fresh()->impressions);
    }

    /**
     * A tab left open for an hour keeps its ids. A creative that has since
     * been paused or has run past its end date is not earning impressions.
     */
    public function test_an_ad_that_is_no_longer_live_is_not_counted(): void
    {
        $paused = $this->ad(['is_active' => false]);
        $expired = $this->ad(['ends_at' => now()->subDay()]);
        $future = $this->ad(['starts_at' => now()->addDay()]);
        $live = $this->ad();

        $this->report([$paused->id, $expired->id, $future->id, $live->id])
            ->assertOk()
            ->assertJson(['counted' => 1]);

        $this->assertSame(0, (int) $paused->fresh()->impressions);
        $this->assertSame(0, (int) $expired->fresh()->impressions);
        $this->assertSame(0, (int) $future->fresh()->impressions);
        $this->assertSame(1, (int) $live->fresh()->impressions);
    }

    public function test_an_unknown_id_is_ignored_rather_than_an_error(): void
    {
        $ad = $this->ad();

        $this->report([$ad->id, 999999])->assertOk()->assertJson(['counted' => 1]);
    }

    /**
     * The ids are client-supplied, so the request has to be bounded. Without a
     * cap one POST is a write to every row in the table.
     */
    public function test_a_beacon_cannot_carry_an_unbounded_list(): void
    {
        $this->report(range(1, 50))->assertStatus(422)->assertJsonValidationErrors('ids');

        $this->postJson('/api/ads/impressions', [])->assertStatus(422);
        $this->postJson('/api/ads/impressions', ['ids' => 'nope'])->assertStatus(422);
    }

    public function test_the_endpoint_is_rate_limited(): void
    {
        $ad = $this->ad();

        for ($i = 0; $i < 20; $i++) {
            $this->report([$ad->id])->assertOk();
        }

        $this->report([$ad->id])->assertStatus(429);
    }

    /** A guest reads the site; a guest sees the ads. No session required. */
    public function test_a_guest_may_report(): void
    {
        $ad = $this->ad();

        $this->assertGuest();
        $this->report([$ad->id])->assertOk();
    }

    // ── What the page hands the client ───────────────────────────────────

    /**
     * The contract between the blade and `ad-impressions.js` is one attribute.
     * A filled slot carries the id; an empty box must not, or an unsold slot
     * reports an impression for nothing.
     */
    public function test_a_filled_slot_carries_its_id_and_an_empty_one_does_not(): void
    {
        $html = view('components.ui.ad-slot', ['position' => 'header'])->render();

        $this->assertStringContainsString('data-ad-position="header"', $html);
        $this->assertStringNotContainsString('data-ad-id', $html, 'An unsold slot must not be reportable.');

        $ad = $this->ad();
        \App\Services\AdService::flush();
        app()->forgetInstance(\App\Services\AdService::class);

        $filled = view('components.ui.ad-slot', ['position' => 'header'])->render();

        $this->assertStringContainsString('data-ad-id="'.$ad->id.'"', $filled);
    }

    /**
     * `click()` 404s on an ad with no `url`, and the slot wrapped every
     * creative in that link regardless — so all six seeded house ads, which
     * carry no URL, were links to an error page on every render.
     *
     * A slot still counts as an impression without a click-through; the two
     * are independent, which is exactly why the CTR column exists.
     */
    public function test_an_ad_with_no_url_is_not_a_link_to_a_404(): void
    {
        $ad = $this->ad(['url' => null]);
        \App\Services\AdService::flush();
        app()->forgetInstance(\App\Services\AdService::class);

        $html = view('components.ui.ad-slot', ['position' => 'header'])->render();

        $this->assertStringContainsString('data-ad-id="'.$ad->id.'"', $html);
        $this->assertStringContainsString($ad->asset_url, $html);
        $this->assertStringNotContainsString('<a href', $html, 'A creative with nowhere to go must not be a link.');

        $this->get(route('ads.click', $ad))->assertNotFound();
    }

    public function test_an_ad_with_a_url_is_still_a_link(): void
    {
        $ad = $this->ad(['url' => 'https://example.com/offer']);
        \App\Services\AdService::flush();
        app()->forgetInstance(\App\Services\AdService::class);

        $html = view('components.ui.ad-slot', ['position' => 'header'])->render();

        $this->assertStringContainsString(route('ads.click', $ad), $html);

        $this->get(route('ads.click', $ad))->assertRedirect('https://example.com/offer');
        $this->assertSame(1, (int) $ad->fresh()->clicks);
    }

    public function test_the_layout_publishes_the_endpoint_the_client_posts_to(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('name="ads-impression-endpoint" content="'.route('ads.impressions').'"', false);
    }

    /** CTR was always 0.0% because the denominator was never written. */
    public function test_ctr_becomes_meaningful_once_impressions_are_counted(): void
    {
        $ad = $this->ad();

        $this->assertSame(0.0, $ad->ctr());

        Ad::whereKey($ad->id)->increment('impressions', 40);
        Ad::whereKey($ad->id)->increment('clicks', 3);

        $this->assertSame(7.5, $ad->fresh()->ctr());
    }
}
