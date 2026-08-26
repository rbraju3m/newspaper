<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Epaper;
use App\Models\EpaperPage;
use App\Services\ImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The printed edition: issues, their page images, and the whole-issue PDF.
 *
 * Everything existed except this — tables, models and the public reader — so
 * an issue had to be inserted by hand. Authorised with `manage-taxonomy`,
 * editor and up, like galleries: uploading today's paper is a daily production
 * job, not an administrative one.
 *
 * Uploads go through `ImageService` whether they are pages or PDFs. A PDF is
 * not convertible, so it gets a row and no ladder, which is exactly what
 * `isConvertible()` is for — and it means one storage path, one folder layout,
 * and a `release()` that can find either.
 */
class EpaperController extends Controller
{
    /**
     * A newspaper issue is nowhere near this long, and the cap is what makes
     * renumbering safe: `page_number` is an unsigned tinyint, and holding every
     * live number below 100 leaves the 100+ band free to park pages in while
     * the order is rewritten.
     */
    private const MAX_PAGES = 99;

    public function index(): View
    {
        Gate::authorize('manage-taxonomy');

        return view('admin.epapers.index', [
            'epapers' => Epaper::query()
                ->withCount('pages')
                ->orderByDesc('date')
                ->orderBy('edition')
                ->paginate(30),
            'editions' => config('site.epaper_editions', ['main' => 'প্রধান']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $epaper = Epaper::create($this->validated($request));

        return redirect()
            ->route('admin.epapers.edit', $epaper->id)
            ->with('status', 'সংখ্যা তৈরি হয়েছে। এবার পৃষ্ঠা যোগ করুন।');
    }

    public function edit(Epaper $epaper): View
    {
        Gate::authorize('manage-taxonomy');

        return view('admin.epapers.edit', [
            'epaper' => $epaper->load('pages'),
            'editions' => config('site.epaper_editions', ['main' => 'প্রধান']),
        ]);
    }

    public function update(Request $request, Epaper $epaper): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $epaper->update($this->validated($request, $epaper));

        return back()->with('status', 'সংখ্যা হালনাগাদ হয়েছে।');
    }

    public function destroy(Epaper $epaper, ImageService $images): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        // Collected before the rows go, released after. `release()` counts
        // references over `epaper_pages.image`/`pdf` and `epapers.pdf`/`cover`,
        // so releasing first would leave every file a reference to itself and
        // free nothing.
        $paths = $this->pathsOf($epaper);

        $epaper->delete();

        foreach ($paths as $path) {
            $images->release($path);
        }

        return redirect()
            ->route('admin.epapers.index')
            ->with('status', 'সংখ্যা মুছে ফেলা হয়েছে।');
    }

    // ── Pages ────────────────────────────────────────────────────────────

    public function storePages(Request $request, Epaper $epaper, ImageService $images): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $validated = $request->validate([
            'files' => ['required', 'array'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
            'section' => ['nullable', 'string', 'max:60'],
        ], [
            'files.required' => 'অন্তত একটি পৃষ্ঠা বেছে নিন।',
            'files.*.mimes' => 'পৃষ্ঠার ছবি JPG, PNG বা WebP হতে হবে।',
            'files.*.max' => 'প্রতিটি পৃষ্ঠার আকার সর্বোচ্চ ১২ মেগাবাইট হতে পারে।',
            // PHP drops an oversize upload before the request is built, so the
            // rule that fires is `uploaded`, not `max`. Page scans are the
            // biggest thing this application accepts and the default
            // upload_max_filesize of 2M cannot carry one — without this the
            // editor gets "must be a file" and no idea why.
            'files.*.uploaded' => 'সার্ভার এত বড় ফাইল নিতে পারেনি। PHP-র upload_max_filesize ও post_max_size সীমা বাড়াতে হবে।',
        ]);

        $next = (int) $epaper->pages()->max('page_number') + 1;

        if ($next + count($validated['files']) - 1 > self::MAX_PAGES) {
            return back()->withErrors([
                'files' => 'একটি সংখ্যায় সর্বোচ্চ '.self::MAX_PAGES.'টি পৃষ্ঠা রাখা যাবে।',
            ]);
        }

        foreach ($validated['files'] as $file) {
            $media = $images->store(
                $file,
                $request->user()->id,
                ['alt' => 'পৃষ্ঠা '.$next],
                $this->folderFor($epaper),
            );

            $epaper->pages()->create([
                'page_number' => $next++,
                'image' => $media->path,
                // The ladder's thumb rung, so the page grid is not 40 full-size
                // broadsheets. Null when GD could not convert, and the reader
                // already falls back to `image`.
                'thumbnail' => $media->conversions['thumb'] ?? null,
                'section' => $validated['section'] ?? null,
            ]);
        }

        $this->refreshCover($epaper);

        return back()->with('status', count($validated['files']).'টি পৃষ্ঠা যুক্ত হয়েছে।');
    }

    /** The whole-issue PDF the reader offers as a download. */
    public function storePdf(Request $request, Epaper $epaper, ImageService $images): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $request->validate([
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:51200'],
        ], [
            'pdf.mimes' => 'শুধু PDF ফাইল আপলোড করা যাবে।',
            'pdf.max' => 'PDF-এর আকার সর্বোচ্চ ৫০ মেগাবাইট হতে পারে।',
            'pdf.uploaded' => 'সার্ভার এত বড় ফাইল নিতে পারেনি। PHP-র upload_max_filesize ও post_max_size সীমা বাড়াতে হবে।',
        ]);

        $old = $epaper->pdf;

        $media = $images->store(
            $request->file('pdf'),
            $request->user()->id,
            [],
            $this->folderFor($epaper),
        );

        $epaper->update(['pdf' => $media->path]);

        // Released after the column is written, so the old value is no longer
        // a reference to itself.
        $images->release($old);

        return back()->with('status', 'পিডিএফ যুক্ত হয়েছে।');
    }

    public function updatePage(Request $request, EpaperPage $page): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $page->update($request->validate([
            'section' => ['nullable', 'string', 'max:60'],
        ]));

        return back()->with('status', 'পৃষ্ঠার তথ্য হালনাগাদ হয়েছে।');
    }

    public function destroyPage(EpaperPage $page, ImageService $images): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $epaper = Epaper::findOrFail($page->epaper_id);
        $paths = array_filter([$page->image, $page->thumbnail, $page->pdf]);

        $page->delete();

        // Closes the gap the delete just left, so page numbers stay 1..n and
        // the next upload does not land on a hole.
        $this->renumber($epaper, $epaper->pages()->orderBy('page_number')->pluck('id')->all());

        $this->refreshCover($epaper->refresh());

        foreach ($paths as $path) {
            $images->release($path);
        }

        return back()->with('status', 'পৃষ্ঠা সরানো হয়েছে।');
    }

    /**
     * Persists a drag as an ordered id list.
     *
     * Scoped to the issue being reordered: a bare `exists:epaper_pages,id`
     * would accept a page from another issue and graft it in, the same shape
     * `CommentRequest` guards `parent_id` against.
     */
    public function reorderPages(Request $request, Epaper $epaper): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $validated = $request->validate([
            'pages' => ['required', 'array'],
            'pages.*' => ['integer', Rule::exists('epaper_pages', 'id')->where('epaper_id', $epaper->id)],
        ]);

        $this->renumber($epaper, $validated['pages']);
        $this->refreshCover($epaper->refresh());

        return back()->with('status', 'পৃষ্ঠার ক্রম সংরক্ষিত হয়েছে।');
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * Rewrites page numbers to 1..n in the order given.
     *
     * In two passes, because `(epaper_id, page_number)` is unique and the
     * obvious single pass collides the moment two pages swap: setting page 2
     * to 1 while a page 1 still exists is a duplicate key, not a reordering.
     *
     * The scratch band is 100+, which is free by construction — `MAX_PAGES` is
     * 99, so no live page number can be in it — and fits the unsigned tinyint
     * with room to spare. Wrapped in a transaction so a failure between the
     * passes cannot leave the issue parked in the scratch band.
     *
     * @param  list<int>  $ids  page ids, in their new order
     */
    private function renumber(Epaper $epaper, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        DB::transaction(function () use ($epaper, $ids): void {
            foreach ($ids as $index => $id) {
                EpaperPage::whereKey($id)->where('epaper_id', $epaper->id)
                    ->update(['page_number' => 100 + $index]);
            }

            foreach ($ids as $index => $id) {
                EpaperPage::whereKey($id)->where('epaper_id', $epaper->id)
                    ->update(['page_number' => $index + 1]);
            }
        });
    }

    /**
     * The cover is what the "previous issues" strip on the public reader shows,
     * and it is a bare path rather than a media id. Kept on page one so it does
     * not dangle when that page is removed or dragged out of first place.
     */
    private function refreshCover(Epaper $epaper): void
    {
        $first = $epaper->pages()->orderBy('page_number')->first();

        $epaper->forceFill([
            'cover' => $first?->thumbnail ?: $first?->image,
        ])->save();
    }

    /** @return list<string> every stored file this issue owns */
    private function pathsOf(Epaper $epaper): array
    {
        $paths = [$epaper->pdf, $epaper->cover];

        foreach ($epaper->pages()->get(['image', 'thumbnail', 'pdf']) as $page) {
            $paths[] = $page->image;
            $paths[] = $page->thumbnail;
            $paths[] = $page->pdf;
        }

        return array_values(array_unique(array_filter($paths)));
    }

    /** Groups an issue's uploads under a folder an editor can recognise. */
    private function folderFor(Epaper $epaper): string
    {
        return 'epaper-'.$epaper->date->toDateString().'-'.$epaper->edition;
    }

    private function validated(Request $request, ?Epaper $epaper = null): array
    {
        return $request->validate([
            'date' => ['required', 'date',
                Rule::unique('epapers')->where(
                    fn ($q) => $q->where('edition', $request->input('edition', 'main'))
                )->ignore($epaper?->id), ],
            'edition' => ['required', 'string', 'max:60'],
        ], [
            'date.required' => 'সংখ্যার তারিখ দিন।',
            'date.unique' => 'এই তারিখে এই সংস্করণের সংখ্যা ইতিমধ্যেই আছে।',
        ]) + ['is_published' => $request->boolean('is_published')];
    }
}
