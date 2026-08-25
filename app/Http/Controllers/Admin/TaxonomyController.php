<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Models\Topic;
use App\Services\HomepageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Tags and topic clusters share a screen — both are flat article groupings. */
class TaxonomyController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('manage-taxonomy');

        return view('admin.taxonomy.index', [
            'tags' => Tag::query()
                ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
                ->orderByDesc('articles_count')
                ->paginate(50, ['*'], 'tags')
                ->withQueryString(),
            'topics' => Topic::withCount('articles')->orderBy('position')->get(),
        ]);
    }

    public function storeTopic(Request $request): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        Topic::create($this->topicRules($request));
        $this->flush();

        return back()->with('status', 'বিষয় যুক্ত হয়েছে।');
    }

    public function updateTopic(Request $request, Topic $topic): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $topic->update($this->topicRules($request, $topic));
        $this->flush();

        return back()->with('status', 'বিষয় হালনাগাদ হয়েছে।');
    }

    public function destroyTopic(Topic $topic): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $topic->articles()->detach();
        $topic->delete();
        $this->flush();

        return back()->with('status', 'বিষয় মুছে ফেলা হয়েছে।');
    }

    public function updateTag(Request $request, Tag $tag): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'slug' => ['nullable', 'string', 'max:120', Rule::unique('tags')->ignore($tag->id)],
        ]);

        // Blanking the slug asks the model to rebuild it from the new name.
        $tag->update(['name' => $validated['name'], 'slug' => $validated['slug'] ?: null]);

        return back()->with('status', 'ট্যাগ হালনাগাদ হয়েছে।');
    }

    public function destroyTag(Tag $tag): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $tag->articles()->detach();
        $tag->delete();

        return back()->with('status', 'ট্যাগ মুছে ফেলা হয়েছে।');
    }

    /** Merge a duplicate tag into a canonical one without losing articles. */
    public function mergeTag(Request $request, Tag $tag): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $validated = $request->validate([
            'target_id' => ['required', 'integer', 'exists:tags,id', 'different:'.$tag->id],
        ]);

        $target = Tag::findOrFail($validated['target_id']);

        $target->articles()->syncWithoutDetaching($tag->articles()->pluck('articles.id'));
        $tag->articles()->detach();
        $tag->delete();

        // Query-builder update: articles_count is not fillable.
        Tag::whereKey($target->id)->update(['articles_count' => $target->articles()->count()]);

        return back()->with('status', 'ট্যাগ একত্র করা হয়েছে।');
    }

    private function topicRules(Request $request, ?Topic $topic = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:150', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('topics')->ignore($topic?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'position' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]) + [
            'is_active' => $request->boolean('is_active'),
            'is_trending' => $request->boolean('is_trending'),
        ];
    }

    private function flush(): void
    {
        Cache::forget('layout.trending');
        HomepageService::flush();
    }
}
