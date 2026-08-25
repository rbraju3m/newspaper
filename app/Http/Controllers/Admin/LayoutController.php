<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HomeBlockType;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\HomeBlock;
use App\Models\Topic;
use App\Services\HomepageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Drag-and-drop front page: editors reorder without a deploy. */
class LayoutController extends Controller
{
    public function index(): View
    {
        Gate::authorize('manage-taxonomy');

        $blocks = HomeBlock::with(['category:id,name', 'topic:id,name'])
            ->orderBy('position')
            ->get()
            ->groupBy('column');

        return view('admin.layout.index', [
            'main' => $blocks->get('main', collect()),
            'sidebar' => $blocks->get('sidebar', collect()),
            'types' => HomeBlockType::cases(),
            'categories' => Category::active()->orderBy('path')->get(['id', 'name', 'path']),
            'topics' => Topic::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $data = $this->validated($request);

        // Append to the end of whichever column it was dropped into.
        $data['position'] = (int) HomeBlock::where('column', $data['column'])->max('position') + 1;

        HomeBlock::create($data);
        HomepageService::flush();

        return back()->with('status', 'ব্লক যুক্ত হয়েছে।');
    }

    public function update(Request $request, HomeBlock $block): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $block->update($this->validated($request));
        HomepageService::flush();

        return back()->with('status', 'ব্লক হালনাগাদ হয়েছে।');
    }

    public function destroy(HomeBlock $block): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $block->delete();
        HomepageService::flush();

        return back()->with('status', 'ব্লক সরানো হয়েছে।');
    }

    /** Persists the new order after a drag; posted as an ordered id list. */
    public function reorder(Request $request): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $validated = $request->validate([
            'main' => ['nullable', 'array'],
            'main.*' => ['integer', 'exists:home_blocks,id'],
            'sidebar' => ['nullable', 'array'],
            'sidebar.*' => ['integer', 'exists:home_blocks,id'],
        ]);

        foreach (['main', 'sidebar'] as $column) {
            foreach ($validated[$column] ?? [] as $position => $id) {
                HomeBlock::whereKey($id)->update([
                    'column' => $column,
                    'position' => $position,
                ]);
            }
        }

        HomepageService::flush();

        return back()->with('status', 'সাজানো সংরক্ষিত হয়েছে।');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(HomeBlockType::class)],
            'title' => ['nullable', 'string', 'max:150'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'topic_id' => ['nullable', 'integer', 'exists:topics,id'],
            'limit' => ['required', 'integer', 'min:1', 'max:24'],
            'column' => ['required', 'in:main,sidebar'],
        ]);

        $type = HomeBlockType::from($data['type']);

        // A category block with no category renders nothing — catch it here
        // rather than leaving a silent hole in the front page.
        // Note the ?? : `nullable` rules omit absent keys from the validated
        // array entirely, so the index is not guaranteed to exist.
        if ($type->needsCategory() && blank($data['category_id'] ?? null)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'category_id' => 'এই ধরনের ব্লকের জন্য একটি বিভাগ বেছে নিন।',
            ]);
        }

        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
