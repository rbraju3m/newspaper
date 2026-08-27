<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Gallery;
use App\Models\Media;
use App\Models\User;
use App\Services\HomepageService;
use Illuminate\Database\Seeder;

/**
 * Fills `/photo`, the last module whose admin shipped ahead of its demo data.
 *
 * Unlike `MediaSeeder` and `EpaperSeeder` this one draws nothing and stores no
 * files. A gallery is *curation* — the desk choosing from photography that
 * already exists — so the seeder models it as curation too, taking the same
 * road the admin's attach-from-library button takes: existing `media` rows,
 * copied onto `gallery_images` as a `media_id` plus a denormalised `path`.
 * That makes it a few dozen row inserts rather than a minute of GD, and it
 * means the galleries are exactly as good as the site's imagery is. On a box
 * where `photos:import` has run they are real photojournalism; on a fresh
 * `db:seed` they are the section plates `MediaSeeder` drew, which is also what
 * every article is showing.
 *
 * Runs after both, because it consumes what they produce.
 *
 * **The pool is restricted to imagery seeding owns** — the `photos/` import and
 * the `seed-*` plates, minus the ad creatives. An editor's own upload sitting
 * in the media library is not demo material, and a seeder that published one
 * into a demo gallery would be a genuine surprise. E-paper pages are excluded
 * by the same rule: a broadsheet page in a photo gallery reads as a mistake.
 *
 * Idempotent by `slug`, the column the table already makes unique — the same
 * shape as `EpaperSeeder`'s `(date, edition)` pair. A gallery somebody made by
 * hand under one of these titles is left exactly as it is, and deleting the
 * rows is enough to force a re-seed.
 */
class GallerySeeder extends Seeder
{
    /**
     * Six published galleries fill the homepage's `photo_row` block exactly —
     * its limit is 6 — and one draft proves the status filter is real: it must
     * show up in the admin list and nowhere on the public site.
     *
     * The captions are written out per image rather than cycled from a pool.
     * A ten-image gallery whose captions repeat every fifth frame looks more
     * broken than one with no captions at all.
     *
     * `উৎসবের রং` is deliberately uncaptioned. Credit-without-caption is the
     * normal state of a gallery just filled — the upload form takes one credit
     * for a whole batch and captions one at a time — and it is exactly the
     * combination that rendered as nothing until `photo-show` was fixed. Demo
     * data that never produces it would not have caught that.
     */
    private const GALLERIES = [
        [
            'title' => 'ঢাকার সকাল',
            'category' => 'bangladesh',
            'days' => 0,
            'description' => 'ভোরের আলো ফোটার পর রাজধানীর পথঘাট — এক দিনের ছবি।',
            'captions' => [
                'ভোর ছ’টা, শহর তখনো ঘুমিয়ে।',
                'কারওয়ান বাজারে দিনের প্রথম চালান।',
                'চায়ের দোকানে সকালের প্রথম কেটলি।',
                'স্কুলের পথে, হাতে টিফিনের বাক্স।',
                'রিকশার সারি অপেক্ষায়, যাত্রীর আশায়।',
                'ফুটপাতে সদ্য সাজানো ফুলের পসরা।',
                'বাসস্ট্যান্ডে অফিসমুখী মানুষের ভিড়।',
                'পুরান ঢাকার গলিতে রোদ পড়েছে।',
                'নদীর ঘাটে নৌকা বাঁধার কাজ চলছে।',
                'দুপুর নামার আগে শেষ ব্যস্ততা।',
            ],
        ],
        [
            'title' => 'মাঠের সপ্তাহ',
            'category' => 'sports',
            'days' => 2,
            'description' => 'গত সাত দিনে খেলার মাঠ থেকে পাঠানো সেরা ফ্রেমগুলি।',
            'captions' => [
                'ইনিংসের প্রথম বলেই আক্রমণ।',
                'উইকেট পড়ার মুহূর্ত, উল্লাসে দল।',
                'সীমানায় দাঁড়িয়ে ক্যাচের অপেক্ষা।',
                'গ্যালারির রং, দর্শকের গলা।',
                'মাঝমাঠে বল দখলের লড়াই।',
                'গোলের পর সতীর্থদের আলিঙ্গন।',
                'পেনাল্টি বক্সে মুহূর্তের সিদ্ধান্ত।',
                'বিরতিতে কোচের নির্দেশনা।',
                'শেষ বাঁশির অপেক্ষায় দুই দল।',
                'ট্র্যাকে শেষ বাঁক, সামনে ফিনিশ লাইন।',
                'পদক হাতে মঞ্চে, চোখে জল।',
                'খেলা শেষে ফাঁকা মাঠ।',
            ],
        ],
        [
            'title' => 'বর্ষা নামল নগরে',
            'category' => 'bangladesh',
            'days' => 4,
            'description' => 'টানা বৃষ্টিতে বদলে যাওয়া শহরের চেহারা।',
            'captions' => [
                'প্রথম বৃষ্টিতে ভিজছে শহর।',
                'জলে ডুবে গেছে পাড়ার রাস্তা।',
                'ছাতা হাতে পার হওয়ার চেষ্টা।',
                'রিকশার চাকা অর্ধেক জলের নিচে।',
                'দোকানের সামনে বালির বস্তা।',
                'বৃষ্টি থামার অপেক্ষায় যাত্রীরা।',
                'স্কুল ছুটির পর কোমরসমান জল।',
                'জমা জলে খেলছে শিশুরা।',
                'মেঘ কেটে গেলে ভেজা শহর।',
            ],
        ],
        [
            'title' => 'উৎসবের রং',
            'category' => 'entertainment',
            'days' => 7,
            'description' => 'মঞ্চ, আলো আর দর্শক — উৎসবের কয়েকটি মুহূর্ত।',
            'captions' => [],
            'count' => 8,
        ],
        [
            'title' => 'বন্দরের ব্যস্ত দিন',
            'category' => 'economy',
            'days' => 11,
            'description' => 'চট্টগ্রাম বন্দরে পণ্য ওঠানামার ব্যস্ততা।',
            'captions' => [
                'জেটিতে ভিড়েছে পণ্যবাহী জাহাজ।',
                'ক্রেন তুলছে কনটেইনার, একের পর এক।',
                'ইয়ার্ডে সাজানো কনটেইনারের সারি।',
                'কাগজপত্র মিলিয়ে দেখছেন কর্মীরা।',
                'ট্রাকের অপেক্ষায় দীর্ঘ লাইন।',
                'খালাসের কাজে ব্যস্ত শ্রমিকেরা।',
                'দিনের শেষ চালান নামছে।',
                'সন্ধ্যায় আলো জ্বলে ওঠে বন্দরে।',
            ],
        ],
        [
            'title' => 'প্রবাসের ঈদ',
            'category' => 'diaspora',
            'days' => 16,
            'description' => 'দেশ থেকে বহু দূরে, তবু ঘরের মতো — প্রবাসী বাংলাদেশিদের ঈদ।',
            'captions' => [
                'ভোরে ঈদের জামাতের প্রস্তুতি।',
                'নামাজ শেষে কোলাকুলি।',
                'দেশে ফোন, ওপাশে মায়ের গলা।',
                'রান্নাঘরে সেমাইয়ের গন্ধ।',
                'একসঙ্গে পাত পেড়ে খাওয়া।',
                'শিশুদের হাতে ঈদের সালামি।',
                'পার্কে জড়ো হয়েছেন প্রবাসীরা।',
                'পোশাকে দেশের রং।',
                'রাত নামলে ঘরে ফেরার পালা।',
            ],
        ],
        [
            'title' => 'সীমান্তের দিনরাত',
            'category' => 'international',
            'days' => 1,
            'status' => 'draft',
            'description' => 'সীমান্ত এলাকার জনজীবন — সম্পাদনার অপেক্ষায়।',
            'captions' => [
                'কাঁটাতারের ওপারে ধানখেত।',
                'সকালের টহল শেষে ফিরছেন সদস্যরা।',
                'হাটের দিন, দুই পারের ভিড়।',
                'নদী পেরিয়ে স্কুলে যাওয়া।',
                'সন্ধ্যা নামে চেকপোস্টে।',
                'রাতের আলোয় সীমান্তরেখা।',
            ],
        ],
    ];

    public function run(): void
    {
        $pool = $this->pool();

        if ($pool->isEmpty()) {
            $this->command->warn('No seeded imagery in the media library — skipping galleries.');

            return;
        }

        $owner = User::query()
            ->whereIn('role', [UserRole::Editor, UserRole::Admin])
            // Editor first: a photo gallery is the desk's work, and the
            // controller authorises it with `manage-taxonomy` rather than a
            // policy of its own.
            ->orderByRaw("field(role, 'editor', 'admin')")
            ->orderBy('id')
            ->first();

        $categories = Category::query()
            ->whereIn('slug', array_column(self::GALLERIES, 'category'))
            ->pluck('id', 'slug');

        $made = 0;

        foreach (self::GALLERIES as $index => $spec) {
            if (Gallery::query()->where('title', $spec['title'])->exists()) {
                continue;
            }

            $this->gallery($spec, $index, $pool, $categories, $owner?->id);
            $made++;

            $this->command->getOutput()->write('.');
        }

        $this->command->getOutput()->writeln('');

        if ($made) {
            // The homepage carries an active `photo_row` block, so the cached
            // payload holds the galleries that existed before this ran.
            HomepageService::flush();
        }

        $this->command->info($made
            ? "Filled {$made} photo galleries from ".$pool->count().' library images.'
            : 'Photo galleries already present.');
    }

    /**
     * The imagery this seeder is allowed to curate, photographs first.
     *
     * `photos:import` writes into a `photos` folder by default and `MediaSeeder`
     * names every plate `seed-*`; between them that is the whole demo library.
     * A non-default `--folder=` on the import puts those photographs outside
     * the pool, which costs nothing — the plates are still there to fall back
     * on, and that is what a fresh box has anyway.
     *
     * @return \Illuminate\Support\Collection<int, Media>
     */
    private function pool(): \Illuminate\Support\Collection
    {
        return Media::query()
            ->where(fn ($q) => $q
                ->where('path', 'like', '%/photos/%')
                ->orWhere('filename', 'like', 'seed-%'))
            ->where('filename', 'not like', 'seed-ad-%')
            ->orderByRaw("case when path like '%/photos/%' then 0 else 1 end")
            ->orderBy('id')
            ->get(['id', 'path', 'caption', 'credit']);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Media>  $pool
     * @param  \Illuminate\Support\Collection<string, int>  $categories
     */
    private function gallery(array $spec, int $index, $pool, $categories, ?int $userId): void
    {
        $status = $spec['status'] ?? 'published';

        $gallery = Gallery::create([
            'title' => $spec['title'],
            'description' => $spec['description'],
            'category_id' => $categories->get($spec['category']),
            'user_id' => $userId,
            'status' => $status,
            'published_at' => $status === 'published' ? now()->subDays($spec['days']) : null,
        ]);

        foreach ($this->deal($spec, $index, $pool) as $position => $media) {
            $gallery->images()->create([
                'media_id' => $media->id,
                'path' => $media->path,
                // The caption is the seeder's; the credit is the photograph's.
                // Inventing provenance for someone else's frame is not a demo
                // detail worth having — a drawn plate says it is symbolic and a
                // real photograph says who took it.
                'caption' => $spec['captions'][$position] ?? null,
                'credit' => $media->credit,
                'position' => $position,
            ]);
        }

        // `galleries.cover` is a plain path rather than a media id — the column
        // predates the media library — and the admin keeps it pointing at the
        // first image. Nothing renders a coverless gallery well: the homepage
        // photo row would show an empty box.
        $first = $gallery->images()->orderBy('position')->first();

        $gallery->update(['cover' => $first?->path]);
    }

    /**
     * Picks this gallery's images out of the pool.
     *
     * Strided by the number of galleries rather than dealt in runs, so two
     * frames shot minutes apart — consecutive in an import — land in different
     * galleries instead of stacking up in one. Deterministic by construction,
     * with no PRNG involved: `SeedImagery` documents why nothing here may call
     * `mt_srand()`, and index arithmetic sidesteps the question entirely.
     *
     * Wrapping is the small-pool case — a fresh box has 54 plates against 62
     * slots — so a picked id is tracked and the cursor walked forward rather
     * than attaching the same photograph to one gallery twice. The admin's
     * attach-from-library path drops a duplicate the same way.
     *
     * @param  \Illuminate\Support\Collection<int, Media>  $pool
     * @return list<Media>
     */
    private function deal(array $spec, int $index, $pool): array
    {
        $want = min($spec['count'] ?? count($spec['captions']), $pool->count());
        $stride = count(self::GALLERIES);

        $picked = [];
        $seen = [];

        for ($n = 0; count($picked) < $want; $n++) {
            $at = ($index + $n * $stride) % $pool->count();

            // Walk forward off a collision. Bounded by the pool size, and the
            // `$want` cap above guarantees a free slot exists.
            while (isset($seen[$pool[$at]->id])) {
                $at = ($at + 1) % $pool->count();
            }

            $media = $pool[$at];
            $seen[$media->id] = true;
            $picked[] = $media;
        }

        return $picked;
    }
}
