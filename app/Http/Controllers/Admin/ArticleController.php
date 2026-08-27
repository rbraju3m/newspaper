<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ArticleStatus;
use App\Enums\ArticleType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ArticleRequest;
use App\Models\Article;
use App\Models\Category;
use App\Models\PushSubscription;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\User;
use App\Services\HomepageService;
use App\Services\ImageService;
use App\Services\PushService;
use App\Support\Bangla;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ArticleController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Article::class);

        $user = $request->user();

        $articles = Article::query()
            ->with(['category:id,name,color', 'author:id,name'])
            // Reporters only ever see their own copy.
            ->when(! $user->role->canPublish(), fn (Builder $q) => $q->where('author_id', $user->id))
            ->when($request->filled('q'), fn (Builder $q) => $q->where('title', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->string('type')))
            ->when($request->filled('category'), fn (Builder $q) => $q->where('category_id', $request->integer('category')))
            ->when($request->filled('author'), fn (Builder $q) => $q->where('author_id', $request->integer('author')))
            ->latest('updated_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.articles.index', [
            'articles' => $articles,
            'categories' => Category::active()->orderBy('path')->get(['id', 'name', 'path', 'parent_id']),
            'authors' => User::staff()->orderBy('name')->get(['id', 'name']),
            'statuses' => ArticleStatus::cases(),
            'types' => ArticleType::cases(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Article::class);

        return view('admin.articles.form', $this->formData(new Article([
            'type' => ArticleType::News,
            'status' => ArticleStatus::Draft,
            'allow_comments' => true,
            'locale' => 'bn',
        ])));
    }

    public function store(ArticleRequest $request): RedirectResponse
    {
        Gate::authorize('create', Article::class);

        $article = DB::transaction(function () use ($request) {
            $article = Article::create($this->payload($request));
            $this->syncRelations($article, $request);

            return $article;
        });

        HomepageService::flush();

        return redirect()
            ->route('admin.articles.edit', $article)
            ->with('status', 'খবরটি সংরক্ষিত হয়েছে।');
    }

    public function edit(Article $article): View
    {
        Gate::authorize('update', $article);

        $article->load(['tags:id,name', 'topics:id,name', 'category']);

        return view('admin.articles.form', $this->formData($article));
    }

    public function update(ArticleRequest $request, Article $article, ImageService $images): RedirectResponse
    {
        Gate::authorize('update', $article);

        $previousImage = $article->image;

        DB::transaction(function () use ($request, $article) {
            $article->update($this->payload($request, $article));
            $this->syncRelations($article, $request);
        });

        // After the write, so the article's own old value is gone and does not
        // read as a reference to itself. release() keeps the file if anything
        // else still points at it.
        if ($article->image !== $previousImage) {
            $images->release($previousImage);
        }

        HomepageService::flush();

        return back()->with('status', 'পরিবর্তন সংরক্ষিত হয়েছে।');
    }

    public function destroy(Article $article): RedirectResponse
    {
        Gate::authorize('delete', $article);

        $article->delete();
        HomepageService::flush();

        return redirect()
            ->route('admin.articles.index')
            ->with('status', 'খবরটি ট্র্যাশে পাঠানো হয়েছে।');
    }

    /** Quick status flip from the list screen, without opening the editor. */
    public function status(Request $request, Article $article): RedirectResponse
    {
        $status = ArticleStatus::tryFrom((string) $request->input('status'));

        abort_unless($status, 422);
        Gate::authorize('update', $article);

        if (in_array($status, [ArticleStatus::Published, ArticleStatus::Scheduled], true)) {
            Gate::authorize('publish', Article::class);
        }

        $article->update([
            'status' => $status,
            'editor_id' => $request->user()->id,
        ]);

        HomepageService::flush();

        return back()->with('status', 'অবস্থা পরিবর্তন হয়েছে: '.$status->label());
    }

    /**
     * Sends the breaking-news push alert for this article.
     *
     * Separate from `status()` and from the `is_breaking` checkbox on purpose.
     * That checkbox is a display flag driving the ticker, and an editor toggles
     * it while writing; a notification is irreversible and lands on a lock
     * screen. Wiring one to the other would make a typo unrecallable, so this
     * is its own deliberate action behind its own button.
     *
     * Authorised on `publish` rather than `update`: reaching every reader on
     * the site is at least as consequential as putting the story on it, and a
     * reporter may do neither.
     */
    public function push(Request $request, Article $article, PushService $push): RedirectResponse
    {
        Gate::authorize('publish', Article::class);

        if (! $push->configured()) {
            return back()->with('error', 'পুশ নোটিফিকেশন কনফিগার করা নেই।');
        }

        // A notification is a link, and a link to an unpublished story is a
        // push straight to a 404.
        if ($article->status !== ArticleStatus::Published) {
            return back()->with('error', 'প্রকাশিত খবরের জন্যই অ্যালার্ট পাঠানো যায়।');
        }

        if ($article->push_sent_at) {
            return back()->with('error', 'এই খবরের অ্যালার্ট আগেই পাঠানো হয়েছে।');
        }

        $result = $push->send($push->payloadFor($article));

        // Stamped even on a partial failure: the story went out, and a second
        // press would send it twice to everyone it already reached.
        $article->forceFill(['push_sent_at' => now()])->save();

        return back()->with('status', 'অ্যালার্ট পাঠানো হয়েছে — '
            .Bangla::digits($result->sent).'টি ডিভাইসে।');
    }

    /**
     * Build the attribute payload. Placement flags and publish state are
     * stripped for anyone without publish rights, so a reporter cannot
     * self-promote a draft onto the front page by posting extra fields.
     */
    private function payload(ArticleRequest $request, ?Article $article = null): array
    {
        $data = $request->validated();
        $user = $request->user();

        unset($data['tags'], $data['topics']);

        if (! $user->role->canPublish()) {
            unset(
                $data['is_lead'], $data['is_featured'], $data['is_breaking'],
                $data['is_pinned'], $data['breaking_until'], $data['published_at'],
            );

            // Reporters submit for review; they never publish.
            if (($data['status'] ?? null) === ArticleStatus::Published->value) {
                $data['status'] = ArticleStatus::Review->value;
            }
        } else {
            $data['editor_id'] = $user->id;
        }

        // Byline assignment is an editorial privilege. A reporter's submitted
        // author_id is ignored entirely — otherwise they could file copy under
        // someone else's name. Editors may reassign freely.
        if ($user->role->canPublish()) {
            $data['author_id'] = $data['author_id'] ?? $article?->author_id ?? $user->id;
        } else {
            unset($data['author_id']);
            $data['author_id'] = $article?->author_id ?? $user->id;
        }

        // Checkboxes are absent from the payload when unticked.
        foreach (['is_lead', 'is_featured', 'is_breaking', 'is_pinned', 'is_premium', 'allow_comments'] as $flag) {
            if (array_key_exists($flag, $data) || $user->role->canPublish()) {
                $data[$flag] = $request->boolean($flag);
            }
        }

        return $data;
    }

    private function syncRelations(Article $article, ArticleRequest $request): void
    {
        // Tags arrive as free text; create the ones that do not exist yet.
        $tagIds = collect($request->validated('tags') ?? [])
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->map(fn ($name) => Tag::firstOrCreate(['name' => $name])->id);

        $article->tags()->sync($tagIds);
        $article->topics()->sync($request->validated('topics') ?? []);

        // Keep the denormalised counters in step. Done as one query-builder
        // UPDATE: articles_count is deliberately not fillable, and this avoids
        // a COUNT round trip per tag.
        if ($tagIds->isNotEmpty()) {
            Tag::whereIn('id', $tagIds)->update([
                'articles_count' => DB::raw(
                    '(SELECT COUNT(*) FROM article_tag WHERE article_tag.tag_id = tags.id)'
                ),
            ]);
        }
    }

    private function formData(Article $article): array
    {
        return [
            'article' => $article,
            'categories' => Category::active()->orderBy('path')->get(['id', 'name', 'path', 'parent_id']),
            'topics' => Topic::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'authors' => User::staff()->orderBy('name')->get(['id', 'name']),
            'statuses' => ArticleStatus::cases(),
            'types' => ArticleType::cases(),

            // The push panel. Counted here rather than in the view so the
            // editor is told the size of the audience *before* pressing a
            // button that cannot be unpressed — "send to 12,431 devices" is a
            // different decision from "send".
            'pushReady' => $ready = app(PushService::class)->configured(),
            'pushAudience' => $ready && $article->exists
                ? PushSubscription::query()->forBreaking()->count()
                : 0,
        ];
    }
}
