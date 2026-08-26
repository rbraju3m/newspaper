<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MediaController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $media = Media::query()
            ->when($request->filled('q'), fn ($q) => $q->where('filename', 'like', '%'.$request->string('q').'%'))
            ->with('uploader:id,name')
            ->latest()
            ->paginate(40)
            ->withQueryString();

        // The editor opens this as a picker via fetch.
        if ($request->ajax()) {
            return response()->json([
                'items' => $media->map(fn (Media $m) => $this->payload($m))->values(),
                'next' => $media->nextPageUrl(),
            ]);
        }

        return view('admin.media.index', compact('media'));
    }

    public function store(Request $request, ImageService $images): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
            'alt' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:500'],
            'credit' => ['nullable', 'string', 'max:255'],
            // A headline or slug: what the upload belongs to, so it can be
            // filed under it. Absent for a library upload.
            'for' => ['nullable', 'string', 'max:500'],
        ], [
            'file.max' => 'ফাইলের আকার সর্বোচ্চ ৮ মেগাবাইট হতে পারে।',
            'file.mimes' => 'শুধু JPG, PNG, WebP বা GIF আপলোড করা যাবে।',
            'file.uploaded' => 'সার্ভার এত বড় ফাইল নিতে পারেনি। PHP-র upload_max_filesize সীমা বাড়াতে হবে।',
        ]);

        $media = $images->store(
            $request->file('file'),
            $request->user()->id,
            $request->only('alt', 'caption', 'credit'),
            $request->string('for')->value() ?: null,
        );

        return response()->json($this->payload($media), 201);
    }

    public function update(Request $request, Media $media): RedirectResponse
    {
        $media->update($request->validate([
            'alt' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:500'],
            'credit' => ['nullable', 'string', 'max:255'],
        ]));

        return back()->with('status', 'ছবির তথ্য হালনাগাদ হয়েছে।');
    }

    /**
     * Deleting is the one media action a reporter may not take. Uploading and
     * re-captioning are part of filing a story, but `ImageService::delete()`
     * removes the original and every derivative from disk, and the library is
     * shared — a reporter could otherwise strip the lead image off somebody
     * else's published article with no way back.
     */
    public function destroy(Media $media, ImageService $images): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $images->delete($media);

        return back()->with('status', 'ছবিটি মুছে ফেলা হয়েছে।');
    }

    private function payload(Media $media): array
    {
        return [
            'id' => $media->id,
            'url' => $media->url,
            'thumb' => $media->conversion('thumb'),
            'card' => $media->conversion('w640'),
            'filename' => $media->filename,
            'width' => $media->width,
            'height' => $media->height,
            'size' => $media->size,
            'alt' => $media->alt,
            'caption' => $media->caption,
            'credit' => $media->credit,
            'path' => $media->path,
        ];
    }
}
