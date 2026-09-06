<?php

use App\Http\Controllers\Site;
use Illuminate\Support\Facades\Route;

// Auth and account routes must be registered before the catch-all {category}
// route at the bottom of this file, or /login resolves as a category slug.
require __DIR__.'/auth.php';

/*
|--------------------------------------------------------------------------
| Public site
|--------------------------------------------------------------------------
|
| Route order matters here. Category paths are materialised and may contain
| slashes ("khela/cricket"), so the category route has to accept `.*` — which
| means it matches everything. It is therefore registered LAST, after every
| fixed-prefix route has had its chance.
|
*/

Route::get('/', Site\HomeController::class)->name('home');

// ── Fixed-prefix listings ────────────────────────────────────────────────
Route::get('/latest', [Site\ListingController::class, 'latest'])->name('latest');
Route::get('/popular', [Site\ListingController::class, 'popular'])->name('popular');
Route::get('/opinion', [Site\ListingController::class, 'opinion'])->name('opinion');

Route::get('/video', [Site\VideoController::class, 'index'])->name('video.index');
Route::get('/video/{article}', [Site\VideoController::class, 'show'])->name('video.show');

Route::get('/photo', [Site\PhotoController::class, 'index'])->name('photo.index');
Route::get('/photo/{gallery:slug}', [Site\PhotoController::class, 'show'])->name('photo.show');

Route::get('/topic/{topic:slug}', Site\TopicController::class)->name('topic.show');
Route::get('/tag/{tag:slug}', Site\TagController::class)->name('tag.show');
Route::get('/author/{user:slug}', Site\AuthorController::class)->name('author.show');

Route::get('/archive', Site\ArchiveController::class)->name('archive');
// FULLTEXT against a longText column — the most expensive public GET.
Route::get('/search', Site\SearchController::class)->middleware('throttle:search')->name('search');

Route::get('/epaper', [Site\EpaperController::class, 'index'])->name('epaper.index');
Route::get('/epaper/{date}', [Site\EpaperController::class, 'show'])
    ->where('date', '\d{4}-\d{2}-\d{2}')
    ->name('epaper.show');

Route::get('/live', Site\LiveController::class)->name('live');

// Fallback shown by the service worker when a navigation cannot reach the
// network. Must render without touching the database.
Route::view('/offline', 'site.offline')->name('offline');

Route::get('/page/{page:slug}', Site\PageController::class)->name('page.show');

// ── Feeds ────────────────────────────────────────────────────────────────
Route::get('/rss', [Site\FeedController::class, 'rss'])->name('feed.rss');
Route::get('/sitemap.xml', [Site\FeedController::class, 'sitemap'])->name('feed.sitemap');
Route::get('/news-sitemap.xml', [Site\FeedController::class, 'newsSitemap'])->name('feed.news-sitemap');

// ── Small JSON/action endpoints used by the front end ────────────────────
Route::get('/api/breaking', [Site\ApiController::class, 'breaking'])
    ->middleware('throttle:polling')->name('api.breaking');
Route::post('/api/articles/{article}/share', [Site\ApiController::class, 'share'])
    ->middleware('throttle:share')->name('api.share');
Route::get('/api/articles/{article}/live', [Site\ApiController::class, 'liveEntries'])
    ->middleware('throttle:polling')->name('api.live');
// Unauthenticated, writes a row, and validates with `email:rfc,dns` — a
// blocking lookup per attempt.
Route::post('/newsletter/subscribe', [Site\NewsletterController::class, 'store'])
    ->middleware('throttle:newsletter')->name('newsletter.subscribe');
Route::get('/newsletter/verify/{subscriber:token}', [Site\NewsletterController::class, 'verify'])->name('newsletter.verify');
// Unsubscribing asks before it acts. A GET that changes state is a GET that
// every mail scanner in the world will trigger on the reader's behalf, which
// is a silent way to lose them; the link renders a confirmation and the button
// posts. The POST is also what `List-Unsubscribe-Post` targets, so a one-click
// unsubscribe from Gmail's own chrome reaches it directly.
Route::get('/newsletter/unsubscribe/{subscriber:token}', [Site\NewsletterController::class, 'confirm'])
    ->name('newsletter.unsubscribe');
Route::post('/newsletter/unsubscribe/{subscriber:token}', [Site\NewsletterController::class, 'destroy'])
    ->middleware('throttle:newsletter')->name('newsletter.unsubscribe.click');
Route::get('/ads/{ad}/click', [Site\AdController::class, 'click'])->name('ads.click');
// Reported by the browser once a slot has actually been in view, not when the
// page was built — see AdController::impressions(). One beacon per page
// carrying every slot that qualified, so this is one request and one query.
Route::post('/api/ads/impressions', [Site\AdController::class, 'impressions'])
    ->middleware('throttle:ads')->name('ads.impressions');
// A guest vote is fingerprinted on IP + user agent, so rotating the agent
// buys another vote. The IP is what has to be limited.
Route::post('/polls/{poll}/vote', [Site\PollController::class, 'vote'])
    ->middleware('throttle:vote')->name('polls.vote');

// Web Push. Guests subscribe too — most readers of a news site are not signed
// in, and breaking news is what they want a notification for. Subscribing is a
// once-per-browser action, so the limit only has to stop a script.
Route::post('/push/subscribe', [Site\PushController::class, 'store'])
    ->middleware('throttle:push')->name('push.subscribe');
Route::delete('/push/subscribe', [Site\PushController::class, 'destroy'])
    ->middleware('throttle:push')->name('push.unsubscribe');

/*
|--------------------------------------------------------------------------
| English edition — must stay above the catch-alls, and carry its own
|--------------------------------------------------------------------------
|
| Bangla is unprefixed and English lives under /en. Every route here is a
| second name for a page that already exists, prefixed `en.`, which is what
| `App\Support\Locale::route()` resolves for a template rendered in either
| edition.
|
| Two ordering rules, and both are the same rule the file already obeys one
| level up. This group must be registered **before** the outer `/{category}`
| catch-all, or `/en` is read as a category path and 404s. And inside the
| group, `/en/{category}` is constrained `.*` in exactly the same way, so it
| must come last within the group or it swallows /en/search and the feeds.
|
| `/en` deliberately has no block-driven front page. The homepage layout is
| an editor-managed set of blocks with one position per column, and a second
| edition of it is a second thing for the desk to keep current — an English
| front page nobody remembered to update is worse than an honest list of the
| latest English stories, which is what this is.
|
*/
Route::prefix(App\Support\Locale::ALTERNATE)
    ->name(App\Support\Locale::ALTERNATE.'.')
    ->middleware('locale:'.App\Support\Locale::ALTERNATE)
    ->group(function () {
        // The English front door is the latest-stories listing, not a second
        // block layout. `ListingController@latest` already paginates, already
        // does the infinite-scroll fragment, and its heading is a translated
        // string — so `/en` is a page that exists rather than one to maintain.
        Route::get('/', [Site\ListingController::class, 'latest'])->name('home');

        Route::get('/search', Site\SearchController::class)
            ->middleware('throttle:search')->name('search');

        // The ticker is in the header, so it renders on these pages too. Its
        // poll has to stay inside the edition — a header that server-renders
        // English breaking news and then swaps it for Bangla 30 seconds later
        // is worse than one that never had it.
        Route::get('/api/breaking', [Site\ApiController::class, 'breaking'])
            ->middleware('throttle:polling')->name('api.breaking');

        // Fixed prefixes, so they sit above this group's own catch-alls for
        // the same reason `/topic` and `/tag` sit above the outer ones.
        Route::get('/topic/{topic:slug}', Site\TopicController::class)->name('topic.show');
        Route::get('/tag/{tag:slug}', Site\TagController::class)->name('tag.show');
        // The newsletter, so the box in the /en footer signs a reader up to
        // the English edition and the links inside that mail stay in it.
        Route::post('/newsletter/subscribe', [Site\NewsletterController::class, 'store'])
            ->middleware('throttle:newsletter')->name('newsletter.subscribe');
        Route::get('/newsletter/verify/{subscriber:token}', [Site\NewsletterController::class, 'verify'])
            ->name('newsletter.verify');
        Route::get('/newsletter/unsubscribe/{subscriber:token}', [Site\NewsletterController::class, 'confirm'])
            ->name('newsletter.unsubscribe');
        Route::post('/newsletter/unsubscribe/{subscriber:token}', [Site\NewsletterController::class, 'destroy'])
            ->middleware('throttle:newsletter')->name('newsletter.unsubscribe.click');

        Route::get('/archive', Site\ArchiveController::class)->name('archive');

        // The e-paper is one printed paper, not one per edition — these are
        // the same issues with English chrome. `{date}` is constrained the
        // same way the Bangla route constrains it, so a malformed date falls
        // through to this group's catch-alls rather than 404ing here.
        Route::get('/epaper', [Site\EpaperController::class, 'index'])->name('epaper.index');
        Route::get('/epaper/{date}', [Site\EpaperController::class, 'show'])
            ->where('date', '\d{4}-\d{2}-\d{2}')
            ->name('epaper.show');

        // One person, two pages: this one lists their English stories.
        Route::get('/author/{user:slug}', Site\AuthorController::class)->name('author.show');

        Route::get('/rss', [Site\FeedController::class, 'rss'])->name('feed.rss');
        Route::get('/sitemap.xml', [Site\FeedController::class, 'sitemap'])->name('feed.sitemap');

        // Same shapes as the Bangla catch-alls, and last for the same reason.
        Route::get('/{category}/{article}/{slug?}', Site\ArticleController::class)
            ->where('category', '.*')
            ->where('article', '[0-9]+')
            ->where('slug', '[^/]*')
            ->name('article.show');

        Route::get('/{category}', Site\CategoryController::class)
            ->where('category', '.*')
            ->name('category.show');
    });

/*
|--------------------------------------------------------------------------
| Catch-all content routes — must stay last
|--------------------------------------------------------------------------
|
| /{category-path}/{id}/{slug}  → article   (id is numeric, so no ambiguity)
| /{category-path}              → category landing
|
*/

Route::get('/{category}/{article}/{slug?}', Site\ArticleController::class)
    ->where('category', '.*')
    ->where('article', '[0-9]+')
    ->where('slug', '[^/]*')
    ->name('article.show');

Route::get('/{category}', Site\CategoryController::class)
    ->where('category', '.*')
    ->name('category.show');
