<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\Media;
use App\Services\HomepageService;
use App\Services\ImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Photo galleries: the module `/photo` has been rendering an empty hub for.
 *
 * Authorised with `manage-taxonomy` — editor and up — rather than a policy of
 * its own. A gallery is curation more than reporting, and it sits alongside
 * topics and the front-page layout in what it does. If reporters ever need to
 * file their own, the upgrade is a `GalleryPolicy` shaped like `ArticlePolicy`;
 * nothing here assumes otherwise.
 *
 * Images are the interesting half. Each one is a `gallery_images` row carrying
 * both a `media_id` and a denormalised `path`, for the same reason articles do:
 * `media_id` is what builds the srcset, `path` is what the plain `src` falls
 * back to. Uploads go through `ImageService` so a gallery image gets the same
 * WebP ladder as everything else on the site.
 */
class GalleryController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('manage-taxonomy');

        return view('admin.galleries.index', [
            'galleries' => Gallery::query()
                ->withCount('images')
                ->with('category:id,name')
                ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->string('q').'%'))
                ->latest('id')
                ->paginate(30)
                ->withQueryString(),
            'categories' => Category::active()->orderBy('path')->get(['id', 'name', 'path']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $gallery = Gallery::create($this->validated($request) + ['user_id' => $request->user()->id]);

        HomepageService::flush();

        return redirect()
            ->route('admin.galleries.edit', $gallery->id)
            ->with('status', 'গ্যালারি তৈরি হয়েছে। এবার ছবি যোগ করুন।');
    }

    public function edit(Gallery $gallery): View
    {
        Gate::authorize('manage-taxonomy');

        return view('admin.galleries.edit', [
            'gallery' => $gallery->load('images.media:id,disk,path,conversions,filename'),
            'categories' => Category::active()->orderBy('path')->get(['id', 'name', 'path']),
        ]);
    }

    public function update(Request $request, Gallery $gallery): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $gallery->update($this->validated($request, $gallery));

        HomepageService::flush();

        return back()->with('status', 'গ্যালারি হালনাগাদ হয়েছে।');
    }

    public function destroy(Gallery $gallery, ImageService $images): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        // The rows cascade from the foreign key, but the files behind them do
        // not: `gallery_images.media_id` is `nullOnDelete`, so dropping the
        // gallery alone would strand every derivative ladder on disk. Released
        // rather than deleted outright, because the media library is shared and
        // a photograph may well be an article's lead image too.
        //
        // Order matters and is not cosmetic. `release()` is reference counted
        // over `gallery_images.path` and `galleries.cover` among others, so
        // releasing before the rows are gone means every file is still a
        // reference to itself and nothing is ever freed.
        $paths = $gallery->images()->pluck('path')->all();

        $gallery->delete();

        foreach ($paths as $path) {
            $images->release($path);
        }

        HomepageService::flush();

        return redirect()
            ->route('admin.galleries.index')
            ->with('status', 'গ্যালারি মুছে ফেলা হয়েছে।');
    }

    // ── Images ───────────────────────────────────────────────────────────

    public function storeImages(Request $request, Gallery $gallery, ImageService $images): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $validated = $request->validate([
            'files' => ['required', 'array', 'max:40'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'credit' => ['nullable', 'string', 'max:255'],
        ], [
            'files.required' => 'অন্তত একটি ছবি বেছে নিন।',
            'files.*.mimes' => 'শুধু JPG, PNG বা WebP আপলোড করা যাবে।',
            'files.*.max' => 'প্রতিটি ছবির আকার সর্বোচ্চ ৮ মেগাবাইট হতে পারে।',
        ]);

        $position = $this->nextPosition($gallery);

        foreach ($validated['files'] as $file) {
            $media = $images->store(
                $file,
                $request->user()->id,
                ['credit' => $validated['credit'] ?? null],
                $gallery->slug,
            );

            $gallery->images()->create([
                'media_id' => $media->id,
                'path' => $media->path,
                'credit' => $validated['credit'] ?? null,
                'position' => $position++,
            ]);
        }

        $this->ensureCover($gallery);

        HomepageService::flush();

        return back()->with('status', count($validated['files']).'টি ছবি যুক্ত হয়েছে।');
    }

    /** Adds images already in the media library, rather than uploading again. */
    public function attachImages(Request $request, Gallery $gallery): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $validated = $request->validate([
            'media' => ['required', 'array', 'max:40'],
            'media.*' => ['integer', 'exists:media,id'],
        ], [
            'media.required' => 'অন্তত একটি ছবি বেছে নিন।',
        ]);

        // Already-attached ids are dropped rather than refused: picking a photo
        // twice in a library grid is a slip, not an error worth a red box.
        $existing = $gallery->images()->pluck('media_id')->filter()->all();
        $wanted = array_values(array_diff(array_unique($validated['media']), $existing));

        $library = Media::query()->whereKey($wanted)->get()->keyBy('id');
        $position = $this->nextPosition($gallery);
        $added = 0;

        foreach ($wanted as $id) {
            if (! $media = $library->get($id)) {
                continue;
            }

            $gallery->images()->create([
                'media_id' => $media->id,
                'path' => $media->path,
                'caption' => $media->caption,
                'credit' => $media->credit,
                'position' => $position++,
            ]);

            $added++;
        }

        $this->ensureCover($gallery);

        HomepageService::flush();

        return back()->with('status', $added
            ? $added.'টি ছবি যুক্ত হয়েছে।'
            : 'ছবিগুলি আগে থেকেই এই গ্যালারিতে আছে।');
    }

    public function updateImage(Request $request, GalleryImage $image): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $image->update($request->validate([
            'caption' => ['nullable', 'string', 'max:500'],
            'credit' => ['nullable', 'string', 'max:255'],
        ]));

        return back()->with('status', 'ছবির তথ্য হালনাগাদ হয়েছে।');
    }

    public function destroyImage(GalleryImage $image, ImageService $images): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $gallery = Gallery::findOrFail($image->gallery_id);
        $path = $image->path;

        // Deleted first, then released — see destroy(). The cover is moved off
        // this path before releasing too, since `galleries.cover` is one of the
        // columns the reference count looks at.
        $image->delete();

        $this->ensureCover($gallery->refresh());

        $images->release($path);

        HomepageService::flush();

        return back()->with('status', 'ছবি সরানো হয়েছে।');
    }

    /**
     * Persists the running order after a drag, as an ordered id list.
     *
     * Scoped to the gallery being reordered: a bare `exists:gallery_images,id`
     * would accept a row belonging to somebody else's gallery and quietly move
     * it here, which is the same graft `CommentRequest` guards `parent_id`
     * against.
     */
    public function reorderImages(Request $request, Gallery $gallery): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $validated = $request->validate([
            'images' => ['required', 'array'],
            'images.*' => ['integer', Rule::exists('gallery_images', 'id')->where('gallery_id', $gallery->id)],
        ]);

        foreach ($validated['images'] as $position => $id) {
            GalleryImage::whereKey($id)->update(['position' => $position]);
        }

        $this->ensureCover($gallery, force: true);

        HomepageService::flush();

        return back()->with('status', 'ছবির ক্রম সংরক্ষিত হয়েছে।');
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * The cover is a plain path rather than a media id — `galleries.cover`
     * predates the media library — so it is kept pointing at the first image
     * rather than left dangling when that image is removed or reordered away.
     */
    private function ensureCover(Gallery $gallery, bool $force = false): void
    {
        $first = $gallery->images()->orderBy('position')->first();

        if (! $force && $gallery->cover && $gallery->images()->where('path', $gallery->cover)->exists()) {
            return;
        }

        $gallery->forceFill(['cover' => $first?->path])->save();
    }

    private function nextPosition(Gallery $gallery): int
    {
        $max = $gallery->images()->max('position');

        return $max === null ? 0 : (int) $max + 1;
    }

    private function validated(Request $request, ?Gallery $gallery = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'published_at' => ['nullable', 'date'],
        ], [
            'title.required' => 'গ্যালারির শিরোনাম লিখুন।',
        ]);

        // `nullable` omits an absent key entirely, so these are not guaranteed
        // to exist in the validated array.
        $data['category_id'] = $data['category_id'] ?? null;

        // Publishing without a time means now; a gallery kept as a draft keeps
        // whatever time was already chosen for it.
        if ($data['status'] === 'published') {
            $data['published_at'] = $data['published_at'] ?? $gallery?->published_at ?? now();
        } else {
            $data['published_at'] = $data['published_at'] ?? null;
        }

        return $data;
    }
}
