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
Route::get('/search', Site\SearchController::class)->name('search');

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
Route::get('/api/breaking', [Site\ApiController::class, 'breaking'])->name('api.breaking');
Route::post('/api/articles/{article}/share', [Site\ApiController::class, 'share'])->name('api.share');
Route::get('/api/articles/{article}/live', [Site\ApiController::class, 'liveEntries'])->name('api.live');
Route::post('/newsletter/subscribe', [Site\NewsletterController::class, 'store'])->name('newsletter.subscribe');
Route::get('/newsletter/verify/{subscriber:token}', [Site\NewsletterController::class, 'verify'])->name('newsletter.verify');
Route::get('/newsletter/unsubscribe/{subscriber:token}', [Site\NewsletterController::class, 'unsubscribe'])->name('newsletter.unsubscribe');
Route::get('/ads/{ad}/click', [Site\AdController::class, 'click'])->name('ads.click');
Route::post('/polls/{poll}/vote', [Site\PollController::class, 'vote'])->name('polls.vote');

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
