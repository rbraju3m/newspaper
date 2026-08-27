<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\HomepageService;
use App\View\Composers\LayoutComposer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(): View
    {
        Gate::authorize('manage-taxonomy');

        return view('admin.categories.index', [
            // Ordered by path so the tree renders in shape with one query.
            'categories' => Category::withCount('articles')->orderBy('path')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        Category::create($this->validated($request));
        $this->flush();

        return back()->with('status', 'বিভাগ যুক্ত হয়েছে।');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $data = $this->validated($request, $category);

        // A category cannot be its own ancestor, or the path builder recurses
        // forever and the tree becomes unreachable.
        if (($data['parent_id'] ?? null) && $this->wouldCycle($category, (int) $data['parent_id'])) {
            return back()->withErrors(['parent_id' => 'একটি বিভাগ নিজের অধীনে যেতে পারে না।'])->withInput();
        }

        $category->update($data);
        $this->flush();

        return back()->with('status', 'বিভাগ হালনাগাদ হয়েছে।');
    }

    public function destroy(Category $category): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        if ($category->articles()->exists()) {
            return back()->withErrors([
                'category' => 'এই বিভাগে খবর আছে, তাই মুছে ফেলা যাবে না। আগে খবরগুলো সরান।',
            ]);
        }

        if ($category->children()->exists()) {
            return back()->withErrors(['category' => 'আগে উপবিভাগগুলো সরান।']);
        }

        $category->delete();
        $this->flush();

        return back()->with('status', 'বিভাগ মুছে ফেলা হয়েছে।');
    }

    /** Drag-and-drop reordering from the tree screen. */
    public function reorder(Request $request): RedirectResponse
    {
        Gate::authorize('manage-taxonomy');

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'exists:categories,id'],
        ]);

        foreach ($validated['order'] as $position => $id) {
            Category::whereKey($id)->update(['position' => $position]);
        }

        $this->flush();

        return back()->with('status', 'ক্রম সংরক্ষিত হয়েছে।');
    }

    private function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('categories')->ignore($category?->id)],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'position' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
            'show_in_nav' => ['nullable', 'boolean'],
            'show_in_footer' => ['nullable', 'boolean'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
        ], [
            'slug.regex' => 'স্লাগে শুধু ছোট হাতের ইংরেজি অক্ষর, সংখ্যা ও হাইফেন ব্যবহার করুন।',
            'color.regex' => 'রঙ #RRGGBB আকারে দিন।',
        ]) + [
            'is_active' => $request->boolean('is_active'),
            'show_in_nav' => $request->boolean('show_in_nav'),
            'show_in_footer' => $request->boolean('show_in_footer'),
        ];
    }

    private function wouldCycle(Category $category, int $parentId): bool
    {
        if ($parentId === $category->id) {
            return true;
        }

        $descendants = Category::where('path', 'like', $category->path.'/%')->pluck('id');

        return $descendants->contains($parentId);
    }

    private function flush(): void
    {
        LayoutComposer::flush();
        HomepageService::flush();
    }
}
