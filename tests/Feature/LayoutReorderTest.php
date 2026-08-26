<?php

namespace Tests\Feature;

use App\Enums\HomeBlockType;
use App\Models\HomeBlock;
use App\Models\User;
use App\Services\HomepageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The homepage layout manager: `LayoutController::reorder()` and the column
 * change hiding inside `update()`.
 *
 * Worth covering because everything here fails quietly. A reorder that half
 * applies, or two blocks that end up sharing a position, does not throw and
 * does not log — the front page simply comes out in an order nobody chose,
 * and `orderBy('position')` breaks ties however InnoDB feels like that day.
 *
 * The positions are asserted against the raw row rather than a model, since
 * `reorder()` writes with the query builder and an already-hydrated model
 * would still be holding the old value.
 */
class LayoutReorderTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        // ->fresh(): a factory-built model holds only what the factory set,
        // and strict mode throws on reading anything it did not.
        return User::factory()->editor()->create()->fresh();
    }

    /** Blocks that resolve to null, so `build()` costs no article queries. */
    private function block(string $column, int $position, HomeBlockType $type = HomeBlockType::Ad): HomeBlock
    {
        return HomeBlock::create([
            'type' => $type,
            'limit' => 5,
            'column' => $column,
            'position' => $position,
            'is_active' => true,
        ]);
    }

    /** @return array{0: string, 1: int} column and position, from the row. */
    private function placed(HomeBlock $block): array
    {
        $row = DB::table('home_blocks')->where('id', $block->id)->first();

        return [$row->column, (int) $row->position];
    }

    private function reorder(array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->editor())
            ->post('/admin/layout/reorder', $payload);
    }

    // ── Reordering ───────────────────────────────────────────────────────

    public function test_reorder_rewrites_positions_within_a_column(): void
    {
        $a = $this->block('main', 0);
        $b = $this->block('main', 1);
        $c = $this->block('main', 2);

        $this->reorder(['main' => [$c->id, $a->id, $b->id]])
            ->assertRedirect();

        $this->assertSame(['main', 0], $this->placed($c));
        $this->assertSame(['main', 1], $this->placed($a));
        $this->assertSame(['main', 2], $this->placed($b));
    }

    public function test_reorder_moves_a_block_between_columns(): void
    {
        $a = $this->block('main', 0);
        $b = $this->block('main', 1);
        $c = $this->block('sidebar', 0);

        // b dragged out of main and dropped above c.
        $this->reorder([
            'main' => [$a->id],
            'sidebar' => [$b->id, $c->id],
        ])->assertRedirect();

        $this->assertSame(['main', 0], $this->placed($a));
        $this->assertSame(['sidebar', 0], $this->placed($b));
        $this->assertSame(['sidebar', 1], $this->placed($c));
    }

    public function test_positions_are_zero_based_and_contiguous(): void
    {
        // Deliberately sparse and overlapping to start with: this is what a
        // column looks like after a delete, and what store() leaves behind
        // when it appends with max(position) + 1 onto an empty column.
        $a = $this->block('main', 7);
        $b = $this->block('main', 7);
        $c = $this->block('main', 40);

        $this->reorder(['main' => [$a->id, $b->id, $c->id]]);

        $positions = DB::table('home_blocks')->where('column', 'main')
            ->orderBy('position')->pluck('position')->all();

        $this->assertSame([0, 1, 2], array_map('intval', $positions));
    }

    public function test_a_column_the_drag_emptied_is_absent_from_the_post(): void
    {
        // The form renders one hidden input per block, so a column with
        // nothing left in it posts no key at all rather than an empty array.
        $a = $this->block('main', 0);
        $b = $this->block('sidebar', 0);

        $this->reorder(['sidebar' => [$a->id, $b->id]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(['sidebar', 0], $this->placed($a));
        $this->assertSame(['sidebar', 1], $this->placed($b));
        $this->assertSame(0, DB::table('home_blocks')->where('column', 'main')->count());
    }

    public function test_a_column_not_posted_is_left_alone(): void
    {
        $a = $this->block('main', 0);
        $side = $this->block('sidebar', 3);

        $this->reorder(['main' => [$a->id]]);

        $this->assertSame(['sidebar', 3], $this->placed($side));
    }

    // ── The cache ────────────────────────────────────────────────────────

    public function test_reorder_flushes_the_homepage_cache(): void
    {
        $a = $this->block('main', 0, HomeBlockType::Ad);
        $b = $this->block('main', 1, HomeBlockType::Newsletter);

        $service = app(HomepageService::class);

        $before = $service->build()['main']->pluck('block.id')->all();
        $this->assertSame([$a->id, $b->id], $before);
        $this->assertTrue(Cache::has(HomepageService::CACHE_KEY));

        $this->reorder(['main' => [$b->id, $a->id]]);

        $this->assertFalse(Cache::has(HomepageService::CACHE_KEY));

        $after = $service->build()['main']->pluck('block.id')->all();
        $this->assertSame([$b->id, $a->id], $after);
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_an_unknown_block_id_is_refused_and_nothing_moves(): void
    {
        $a = $this->block('main', 0);
        $b = $this->block('main', 1);

        $this->reorder(['main' => [$b->id, 9999, $a->id]])
            ->assertSessionHasErrors('main.1');

        $this->assertSame(['main', 0], $this->placed($a));
        $this->assertSame(['main', 1], $this->placed($b));
    }

    public function test_a_non_integer_id_is_refused(): void
    {
        $a = $this->block('main', 0);

        $this->reorder(['main' => ['not-an-id']])
            ->assertSessionHasErrors('main.0');

        $this->assertSame(['main', 0], $this->placed($a));
    }

    // ── Authorisation ────────────────────────────────────────────────────

    public function test_a_reporter_cannot_reorder(): void
    {
        $a = $this->block('main', 0);
        $b = $this->block('main', 1);

        $this->reorder(
            ['main' => [$b->id, $a->id]],
            User::factory()->reporter()->create()->fresh()
        )->assertForbidden();

        $this->assertSame(['main', 0], $this->placed($a));
    }

    public function test_a_reader_gets_a_404(): void
    {
        $a = $this->block('main', 0);

        $this->reorder(['main' => [$a->id]], User::factory()->create()->fresh())
            ->assertNotFound();
    }

    public function test_an_admin_can_reorder(): void
    {
        $a = $this->block('main', 0);
        $b = $this->block('main', 1);

        $this->reorder(
            ['main' => [$b->id, $a->id]],
            User::factory()->admin()->create()->fresh()
        )->assertRedirect();

        $this->assertSame(['main', 0], $this->placed($b));
    }

    // ── The other way a block changes column ─────────────────────────────

    /**
     * The per-block settings form carries a `column` select, so a block can
     * cross columns without the drag handle ever being touched. `update()`
     * writes the new column and leaves `position` alone, which lands the block
     * on top of whatever already sits at that index in the destination.
     */
    public function test_moving_a_block_between_columns_from_the_settings_form_does_not_collide(): void
    {
        $moving = $this->block('main', 1);
        $this->block('sidebar', 0);
        $this->block('sidebar', 1);

        $this->actingAs($this->editor())
            ->put('/admin/layout/'.$moving->id, [
                'type' => HomeBlockType::Ad->value,
                'limit' => 5,
                'column' => 'sidebar',
                'is_active' => '1',
            ])->assertRedirect();

        $positions = DB::table('home_blocks')->where('column', 'sidebar')
            ->pluck('position')->all();

        $this->assertSame(
            count($positions),
            count(array_unique($positions)),
            'Two sidebar blocks share a position, so their order on the front page is whatever InnoDB returns.'
        );

        // And it lands at the end, which is where a drop into that column
        // would have put it — not silently in the middle.
        $this->assertSame(['sidebar', 2], $this->placed($moving));
    }

    public function test_editing_a_block_without_changing_column_leaves_its_position_alone(): void
    {
        $first = $this->block('main', 0);
        $second = $this->block('main', 1);

        $this->actingAs($this->editor())
            ->put('/admin/layout/'.$first->id, [
                'type' => HomeBlockType::Ad->value,
                'title' => 'নতুন শিরোনাম',
                'limit' => 8,
                'column' => 'main',
                'is_active' => '1',
            ])->assertRedirect();

        $this->assertSame(['main', 0], $this->placed($first));
        $this->assertSame(['main', 1], $this->placed($second));
        $this->assertSame('নতুন শিরোনাম', $first->fresh()->title);
    }

    public function test_a_block_added_to_an_empty_column_starts_at_zero(): void
    {
        // store() appended with `(int) max(...) + 1`, which is 1 on an empty
        // column because max() returns null. Harmless on its own, but it meant
        // the two append paths disagreed about where the end of a column is.
        $this->actingAs($this->editor())
            ->post('/admin/layout', [
                'type' => HomeBlockType::Ad->value,
                'limit' => 5,
                'column' => 'sidebar',
                'is_active' => '1',
            ])->assertRedirect();

        $this->assertSame(0, (int) DB::table('home_blocks')->where('column', 'sidebar')->value('position'));
    }
}
