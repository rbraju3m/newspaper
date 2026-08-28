<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ArticleType;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\LiveEntry;
use App\Services\HomepageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class LiveEntryController extends Controller
{
    public function index(Article $article): View
    {
        Gate::authorize('update', $article);
        abort_unless($article->type === ArticleType::Live, 404);

        return view('admin.live.index', [
            'article' => $article,
            'entries' => $article->liveEntries()->with('author:id,name')->paginate(40),
        ]);
    }

    public function store(Request $request, Article $article): RedirectResponse
    {
        Gate::authorize('update', $article);
        abort_unless($article->type === ArticleType::Live, 404);

        $validated = $request->validate([
            'headline' => ['nullable', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:8000'],
            'embed_url' => ['nullable', 'url', 'max:2048'],
            'published_at' => ['nullable', 'date'],
        ], [
            'body.required' => 'আপডেটের বিবরণ লিখুন।',
        ]);

        $article->liveEntries()->create([
            ...$validated,
            'user_id' => $request->user()->id,
            'is_pinned' => $request->boolean('is_pinned'),
            'is_key' => $request->boolean('is_key'),
            'published_at' => $validated['published_at'] ?? now(),
        ]);

        // A live update changes what the front page should be showing.
        HomepageService::flush();

        return back()->with('status', 'আপডেট যুক্ত হয়েছে।');
    }

    /**
     * A live entry has no policy of its own — permission to edit one is
     * permission to edit the article it belongs to. That article has to be
     * loaded before it can be handed to the gate, and `$entry` arrives from
     * route-model binding, which is a single-row fetch: strict mode does not
     * flag reading the relation off it (see CLAUDE.md), so this was a silent
     * extra query on every edit and delete.
     */
    public function update(Request $request, LiveEntry $entry): RedirectResponse
    {
        Gate::authorize('update', $entry->loadMissing('article')->article);

        $entry->update($request->validate([
            'headline' => ['nullable', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:8000'],
        ]) + [
            'is_pinned' => $request->boolean('is_pinned'),
            'is_key' => $request->boolean('is_key'),
        ]);

        return back()->with('status', 'আপডেট সম্পাদিত হয়েছে।');
    }

    public function destroy(LiveEntry $entry): RedirectResponse
    {
        Gate::authorize('update', $entry->loadMissing('article')->article);

        $entry->delete();

        return back()->with('status', 'আপডেট মুছে ফেলা হয়েছে।');
    }
}
