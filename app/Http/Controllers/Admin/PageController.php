<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PageController extends Controller
{
    public function index(): View
    {
        Gate::authorize('manage-site');

        return view('admin.pages.index', ['pages' => Page::orderBy('title')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-site');

        Page::create($this->validated($request));

        return back()->with('status', 'পাতা তৈরি হয়েছে।');
    }

    public function update(Request $request, Page $page): RedirectResponse
    {
        Gate::authorize('manage-site');

        $page->update($this->validated($request, $page));

        return back()->with('status', 'পাতা হালনাগাদ হয়েছে।');
    }

    public function destroy(Page $page): RedirectResponse
    {
        Gate::authorize('manage-site');

        $page->delete();

        return back()->with('status', 'পাতা মুছে ফেলা হয়েছে।');
    }

    private function validated(Request $request, ?Page $page = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('pages')->ignore($page?->id)],
            'body' => ['nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
        ], [
            'slug.regex' => 'স্লাগে শুধু ছোট হাতের ইংরেজি অক্ষর, সংখ্যা ও হাইফেন ব্যবহার করুন।',
        ]) + ['is_active' => $request->boolean('is_active')];
    }
}
