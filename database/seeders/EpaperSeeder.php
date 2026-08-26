<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Epaper;
use App\Models\Media;
use App\Models\User;
use App\Services\ImageService;
use Database\Seeders\Support\SeedImagery;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/**
 * A week of back issues, so `/epaper` is a reader rather than an empty page.
 *
 * The pages are drawn, not scanned. What has to be right at the size the grid
 * shows them is the *shape* of a newspaper — masthead, column rules, headline
 * bars, a picture block — and `SeedImagery::newspaperPage()` draws exactly
 * that. A photograph in a page slot reads as a mistake; a drawn page reads as
 * a page.
 *
 * Runs after MediaSeeder, which is where the shared imagery conventions live.
 *
 * Idempotent. An issue is looked up by its (date, edition) pair — the same
 * pair the table makes unique — so re-running relinks nothing and redraws
 * nothing. Deleting the rows is enough to force a redraw, and `demo:purge`
 * already lists both tables.
 */
class EpaperSeeder extends Seeder
{
    private const ISSUES = 6;

    private const PAGES_PER_ISSUE = 8;

    /** Wide enough for the whole ladder below w1600, which is not upscaled to. */
    private const PAGE_W = 1000;

    private const PAGE_H = 1400;

    private const QUALITY = 86;

    public function run(): void
    {
        if (! function_exists('imagewebp')) {
            $this->command->warn('GD has no WebP support — skipping e-paper.');

            return;
        }

        $service = app(ImageService::class);
        $uploader = User::query()->where('role', UserRole::Admin)->orderBy('id')->first();

        // Section colours, so consecutive pages are visibly different from one
        // another rather than six variations of the brand red.
        $palette = Category::query()->whereNull('parent_id')->orderBy('id')
            ->pluck('color')->filter()->values();

        $made = 0;

        for ($i = 0; $i < self::ISSUES; $i++) {
            $date = now()->subDays($i)->toDateString();

            if (Epaper::query()->where('date', $date)->where('edition', 'main')->exists()) {
                continue;
            }

            $this->issue($service, $uploader?->id, $date, $palette);
            $made++;

            $this->command->getOutput()->write('.');
        }

        $this->command->getOutput()->writeln('');

        $this->command->info($made
            ? "Drew {$made} e-paper issues of ".self::PAGES_PER_ISSUE.' pages.'
            : 'E-paper issues already present.');
    }

    private function issue(ImageService $service, ?int $userId, string $date, $palette): void
    {
        $epaper = Epaper::create([
            'date' => $date,
            'edition' => 'main',
            'is_published' => true,
        ]);

        $sections = ['প্রথম পাতা', 'শেষ পাতা', 'খেলা', 'বাণিজ্য', 'সম্পাদকীয়', 'আন্তর্জাতিক', 'বিনোদন', 'শেষের পাতা'];

        for ($page = 1; $page <= self::PAGES_PER_ISSUE; $page++) {
            $colour = $palette->get(($page - 1) % max(1, $palette->count())) ?: '#C8102E';

            // Seeded per page per issue, so a redraw reproduces the same paper
            // rather than a different one.
            $image = (new SeedImagery(crc32($date.'-'.$page)))
                ->newspaperPage($colour, self::PAGE_W, self::PAGE_H, front: $page === 1);

            $media = $this->store($service, $image, $page.'.jpg', $userId, 'epaper-'.$date.'-main');

            $epaper->pages()->create([
                'page_number' => $page,
                'image' => $media->path,
                'thumbnail' => $media->conversions['thumb'] ?? null,
                'section' => $sections[$page - 1] ?? null,
            ]);
        }

        $first = $epaper->pages()->orderBy('page_number')->first();

        $epaper->forceFill(['cover' => $first?->thumbnail ?: $first?->image])->save();
    }

    /** Hands a drawn page to ImageService as though it had been uploaded. */
    private function store(ImageService $service, \GdImage $image, string $filename, ?int $userId, string $folder): Media
    {
        // tempnam() has no extension and does not need one: ImageService reads
        // the extension back off the sniffed mime type, not the name.
        $tmp = tempnam(sys_get_temp_dir(), 'seed-epaper-');

        imagejpeg($image, $tmp, self::QUALITY);
        imagedestroy($image);

        try {
            return $service->store(
                new UploadedFile($tmp, $filename, 'image/jpeg', null, true),
                $userId,
                ['alt' => 'ই-পেপার পৃষ্ঠা'],
                $folder,
            );
        } finally {
            File::delete($tmp);
        }
    }
}
