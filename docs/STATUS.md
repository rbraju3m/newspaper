# Project Status

**Last updated:** 28 August 2026
**Branch:** `main`
**Environment:** running locally against MySQL, seeded with demo content

---

## Where things stand

| Phase | Scope | Status |
|---|---|---|
| 0 | Foundation — Laravel, design system, fonts, Bangla helpers | **Done** |
| 1 | Data layer — migrations, models, factories, seeders | **Done** |
| 2 | Public site — homepage, categories, articles, search, feeds | **Done** |
| 3 | Auth & reader features — accounts, bookmarks, comments | **Done** |
| 4 | Admin CMS — dashboard, editor, moderation, layout manager | **Done** |
| 5 | Interactivity — PWA, live blog, toasts, polish | **Done** |
| 6 | SEO & performance — `srcset`, Lighthouse, Core Web Vitals | **Started** — imagery live, Lighthouse 99/98 mobile, cold homepage halved; AVIF remains, and it is blocked on the box |
| 7 | Hardening & launch — tests, backups, deploy runbook | **Started** — 756 tests covering both halves, every editor-written body sanitised, demo purge, deploy runbook, nightly backups verified, an off-site copy and a dead-man's-switch built, a health endpoint that fails when a dependency does, error alerting, scheduled publishing, self-healing counters, rate limits and a demo identity that declares itself. **One thing left, and it needs credentials rather than code:** the off-site copy has never run against a real bucket |

### By the numbers

Counted rather than remembered, 30 August 2026.

| | |
|---|---|
| PHP files (app/database/routes/config) | 179 |
| Models · Enums · Policies · Services | 24 · 5 · 3 · 8 |
| Controllers · Artisan commands | 47 · 14 |
| Blade templates | 115 |
| Test files · tests · assertions | 51 · 756 · 3,243 |
| Routes | 140 total · 73 admin |
| Database tables | 39 |
| Content on this box | 55 categories · 374 articles · 110 comments · 37 users |
| Demo modules | 5 e-paper issues (40 pages) · 8 photo galleries (64 images) |
| Imagery | 152 media · 744 WebP derivatives · 78 MB on disk |
| Bundle (gzipped) | 17.0 KB CSS · 25.2 KB JS |

The code figures moved on 30 August and the content ones did not: `Services`
and `Artisan commands` each gained one — `RedirectResolver` and
`redirects:import` — and `Support` gained `Avatar`, which is what carries the
PHP-file count from 175 to 179. The JS bundle is 1.2 KB heavier than this table
last claimed; that predates those three commits, none of which shipped
JavaScript. Blade templates went the other way on 30 August, 116 to 115:
`welcome.blade.php` was Laravel's stock file, reachable by no route and
referenced by nothing, and was deleted.

Some of those differ from what a fresh `db:seed` produces, and the difference is
manual verification rather than drift in the seeders: `EpaperSeeder` draws
**six** issues and this box has five, because one was deleted by hand while the
e-paper admin's delete path was being checked. The gallery and comment counts
are likewise a few above the seeded figures for the same reason. Re-seeding
does not restore them — every imagery seeder is idempotent and skips what
already exists.

One more piece of drift is worth knowing before it costs somebody twenty
minutes: **`admin@newspaper.test` does not exist on this box.** `UserSeeder`
creates it and the README lists it, but it was removed here at some point and
the admin account is a real address instead. `editor@newspaper.test` and
`reader@newspaper.test` are both present, and an editor reaches everything
under `manage-taxonomy` — which is most of the admin, but not ads, pages, users
or settings.

### Verification currently passing

- `php artisan test` — 756 tests, 3,243 assertions, nothing skipped or risky
- PHP lint clean across all 175 files; all 115 Blade templates compile
- `npm run build` clean; `route:list --except-vendor` resolves all 139
- Every reachable GET route crawled against seeded data — public and admin,
  signed in — with no 500 and nothing in `laravel.log`
- The suite also passes with the framework's lazy-loading guard forced on for
  single-row fetches, which is what makes that sweep repeatable

The checks above were ad-hoc scripts once. **They are committed tests now**,
and this is what they cover — 756 tests, 3,243 assertions across 51 files:

| File | Tests | Covers |
|---|---|---|
| `HarnessTest` | 6 | driver, FULLTEXT index, factories, strict mode — and that the lazy-load guard actually covers single-row fetches, which Laravel's does not |
| `BrandingTest` | 14 | what a fresh install presents itself as: that the six static pages are written rather than generated (and that the filler detector still recognises filler), that the seeded imprint cannot be mistaken for a real one, that no social link is a bare network homepage, that `favicon.ico` is a real multi-size icon rather than an empty file, and that the manifest's maskable icon is its own file with every named icon present |
| `AdImpressionTest` | 13 | ad impressions and the click-through: that a reported slot is counted, that every slot on a page costs one query rather than one each, that a paused or expired creative is not counted, that the beacon is capped and rate-limited, that a filled slot carries an id and an empty box does not, and that an ad with nowhere to go is not a link to a 404 |
| `AdCreativeSizingTest` | 11 | that a creative is served at the size the slot needs: the media link an upload records, the ladder and a matching `sizes`, an untracked creative degrading to a plain `src`, a slot-sized creative keeping its single WebP rung, the accessor refusing to lazy-load, the cached payload round-tripping a second model class, and the reserved box surviving |
| `PublicRoutesTest` | 23 | every public URL, nested category paths, canonical redirect, draft visibility, staff preview, feeds parse as XML, output-buffer balance |
| `AdminAuthorizationTest` | 22 | the role matrix requested by URL, plus publish, cross-author edit, media delete, self-delete |
| `Unit/PolicyTest` | 14 | Article/Comment/User policy decision tables and the role ladder, no database |
| `SearchTest` | 7 | Bangla `MATCH ... AGAINST`, category and date filters, LIKE fallback under three characters |
| `CommentModerationTest` | 9 | who may moderate, and `comments_count` through approve, unapprove, delete and bulk |
| `RegistrationTest` | 13 | registration, Bangla-digit phone normalisation and uniqueness, email lowercasing, newsletter as a separate consent |
| `LoginTest` | 13 | login by email or phone (both digit systems), suspended accounts, session rotation, per-identifier throttling |
| `EmailVerificationTest` | 8 | signed links, tampered hash, expired link, resend |
| `PasswordResetTest` | 7 | request, reset, old password stops working, token cannot be reused |
| `AccountProfileTest` | 16 | profile, email change invalidating verification, password change, account deletion — including that a deleted reader's comments still render on the article — preferences and newsletter sync |
| `BookmarkTest` | 6 | toggle, the 401-for-guests contract the Alpine store depends on, per-reader isolation |
| `ReadingHistoryTest` | 9 | progress as a high-water mark, seconds accumulating, per-reader clearing |
| `CommentPostingTest` | 17 | who may post, pending-by-default, rate limit, thread flattening, edit window, likes, report auto-hide |
| `NewsletterTest` | 8 | subscribe, verify, unsubscribe, resubscribe |
| `PollVotingTest` | 10 | guest fingerprinting, double-vote refusal, cross-poll option rejection, total equals sum of options |
| imagery suite | 33 | `ResponsiveImageTest`, `MediaBackfillTest`, `ArticleImageSyncTest`, `AdAssetTest`, `MediaUploadTest` |
| `Unit/HtmlSanitizerTest` | 68 | the HTML allow-list: script, handler, `javascript:`, entity-encoded and tab-split URLs, `data:` images, conditional comments, foreign content, off-host iframes — and that every verdict is idempotent |
| `ErrorPageTest` | 13 | every error page in Bangla, the 404's search box, the 429's retry window — and that the 5xx pages render with the database gone |
| `RateLimitTest` | 10 | every named limiter, the headroom below each ceiling, that authenticated buckets are per-account rather than per-address, and that logout is deliberately exempt |
| `ArticleCounterTest` | 14 | `categories.articles_count` and `users.articles_count` through publish, unpublish, category move, byline change, trash, restore and both force-delete shapes — plus `counters:recompute` correcting drift a bulk update caused |
| `ScheduledPublishingTest` | 9 | that a due article publishes and a future one does not, that the editor's chosen time survives, that drafts are untouched, and that a guest goes from 404 to reading it |
| `ErrorAlertTest` | 16 | what is recorded, what is pushed and — mostly — what is not: the per-fault throttle, the hourly cap across fingerprints, the framework's own filtering of 404s and validation failures, that a failing channel never escapes into the exception handler, the Slack/Discord payload split, and `errors:digest` grouping |
| `BackupTest` | 10 | that `backup:run` writes a dump holding rows and not just schema, that Bangla survives it, that the archive uses relative paths, the completion-marker check, the public-disk refusal, and that pruning never takes the last backup |
| `DemoPurgeTest` | 12 | what `demo:purge` deletes and what it keeps, the seeded logins going even when one is an admin, `--keep`, the lockout refusal, the media ladder coming off disk, and the counter and cache resets |
| `ContentSanitizeTest` | 22 | that all three unescaped bodies are sanitised on write — the article model and editor form, live-blog append and edit (including the JSON the polling client feeds to `x-html`), and static pages — plus `content:sanitize` over every target, trashed rows included, leaving `updated_at` alone |
| `EpaperSeederTest` | 5 | `EpaperSeeder` — a complete drawn issue, the dates it skips, that a hand-made issue is left alone, and that a redraw reproduces the same paper |
| `EpaperAdminTest` | 22 | the e-paper admin — one issue per edition per day, page uploads numbered in order with thumbnails, the whole-issue PDF and its replacement, renumbering against the unique constraint, deletion taking its files, and the public reader |
| `GalleryAdminTest` | 25 | the photo gallery admin — creating, the Bangla slug, uploads writing both columns and building the ladder, attaching from the library, drag ordering, the cover that follows it, deletion taking its files off disk without reaping a photograph an article still uses, the denormalised count, and the public hub |
| `MediaSeederTest` | 7 | what `MediaSeeder` heals and, more importantly, what it refuses to touch — imagery that is actually on disk |
| `NewsletterDigestTest` | 18 | `newsletter:send` — who receives an edition and who never does, the daily/weekly split, the double-send guard, per-section filtering, that a quiet news day sends nothing, the desk's lead leading the email, the one-click headers, and that one rejected address does not end the run |
| `PushNotificationTest` | 26 | Web Push end to end — a guest subscribing, the endpoint as identity, the account switch standing browsers down, the payload contract with `sw.js`, sending, a 410 pruning the row, a 500 keeping it, the double-send guard, and the role matrix on the send button |
| `Unit/SeedImageryTest` | 10 | `SeedImagery`'s deterministic RNG — the seed mixed in exact 32-bit arithmetic against arbitrary-precision references, that no seed raises the precision deprecation, and that an overflowing seed still draws the same image twice |
| `GallerySeederTest` | 14 | `GallerySeeder` — the seven demo galleries, the count its model events keep, the credit copied off the photograph, the uncaptioned gallery, the draft staying unpublished, a hand-made gallery left alone, the pool it refuses to curate, and dealing without repeats out of a fresh box's 54 plates |
| `PhotoImportTest` | 8 | `photos:import` — the ladder built from a real folder, the transcode that stops a 2 MB PNG becoming the `src` fallback, transparency flattened onto white, idempotency by filename, deterministic assignment, and that a dry run writes nothing |
| `LiveBlogTest` | 23 | the live blog end to end: appending, the backdated correction, pinned above newest, editing that must not move an entry, who may run one — and the polling endpoint's cursor, including a burst larger than one page |
| `LayoutReorderTest` | 14 | the front-page layout manager: a drag rewriting positions within a column and across them, the emptied column the form omits entirely, the homepage cache flushing so the new order is what renders, unknown block ids refused with nothing moved, the role matrix — and the collision the settings form's own column select used to cause |
| `HomepageCacheTest` | 8 | what the front page costs to build and to read back: that the three card relations load once for the whole page however many blocks produced it, that every card leaves the build with its relations already on it, that a second build touches no content table, that the payload is stored packed and round-trips its model graph, that a truncated entry and one written by an older build both rebuild rather than 500, and that a cached null is not mistaken for a miss |
| `BackupSyncTest` | 14 | the off-site copy and the monitoring around it: what goes up and what is skipped as already there, that a dry run writes nothing, that an unconfigured remote skips rather than failing the nightly cron, that a copy arriving truncated is deleted from the remote and fails the run, remote retention keeping the newest, `backup:run` taking the artifacts off-site itself — and the heartbeat, pinged on success, `/fail` on failure, silent when unconfigured, and never able to fail a good backup |
| `FeedContentsTest` | 20 | what the three feeds *say*, as opposed to whether they parse: the RSS channel's own description of the publication, an item's canonical link, byline, pubDate and excerpt, the enclosure's real image type, the 40-item cap, that a draft, a scheduled story and a retraction all stay out, ordering newest-first, escaping that survives a round trip, the sitemap's homepage/category/article set with an inactive category and a draft absent, and Google News's 48-hour window at both edges |
| `EpaperReaderTest` | 31 | the public e-paper reader: which issue `/epaper` opens and that an unpublished newer one does not take it over, a back issue by its own date, the rail's ordering, its cap of 14, that it never offers an unpublished issue and marks the one being read, pages in `page_number` order, the thumbnail fallback, the PDF button appearing only when there is a PDF, the empty states for a fresh install and for an issue published before its pages are uploaded, that a malformed date falls through to the catch-all routes, and — since editions arrived — how a day holding two of them resolves, how `?edition=` names one, the canonical URL and its 301, the edition switcher, and that the rail stays inside the edition being read |
| `OAuthSignInTest` | 24 | Google and Facebook sign-in: an unknown provider and one with no credentials both 404, a configured one reaches its real consent screen, the login page offers only what is configured, the three cases `resolveUser()` decides between, the refusal that stops an unverified provider email taking over a local account, a second provider linking to the same reader, no email and no id and a suspended reader and a throwing provider all refused, session fixation with a control that proves the harness, an unverified address creating an unverified account that gets the mail, and what a deleted reader is told on both the identity and the address route |
| `RedirectTest` | 36 | old-CMS URL preservation: that the lookup hangs off the 404 so a live page wins and a resolving request never reads the table, deep legacy paths and `firstOrFail()` 404s, matching with or without a leading slash and case-insensitively, query-string rules and query carry-over, the self-loop and POST and admin/api guards, hit counting that leaves `updated_at` alone, and `redirects:import` — normalising, upserting, `--dry-run`, and the rules it warns will never fire because live content already answers them |
| `AvatarTest` | 14 | the locally drawn fallback avatar: that no page carrying a face reaches a third-party host (with a control proving the page really renders one), that Bangla initials survive into the SVG, that the data URI is inert in every attribute it is printed in, that every palette colour clears WCAG AA against white — computed, not trusted — and that the one category colour which does not stays out, that a reader's colour is stable and that readers do not all collapse onto one, that an uploaded or provider photograph still wins, and that structured data gets a real photograph or no key at all |

Writing them turned up six defects that every manual pass had missed — see
"What the test pass found" below.

Every area this section once listed as uncovered now has a file. What
`OAuthSignInTest` cannot reach is the half only a real provider has — the
token exchange, the state parameter, and whether the credentials registered at
Google match this deployment. It fakes the provider, so it proves the
controller's decisions and nothing about the handshake.

---

## What works end to end

**Public** — homepage assembled from editor-configured blocks; nested category
pages; article pages with JSON-LD, share bar, reading progress and font
controls; Bangla full-text search with filters; date archive; video and photo
hubs; topic clusters; author pages; e-paper reader; RSS, sitemap and Google News
sitemap.

**Readers** — register → email verification → login (by email *or* phone, in
Bangla or ASCII digits) → bookmark → comment. Password reset, Google/Facebook
OAuth, reading history that resumes where they stopped, followed categories,
newsletter preferences, account deletion.

**Editorial** — an editor can create, publish and place a story on the front
page in one request. Comment moderation single and bulk. Drag-and-drop homepage
layout. Media library with a WebP derivative ladder. Category tree, tags with
merge, topic clusters, users, ads, static pages, settings.

**Live blog** — `type=live` articles get an append-only timeline with a
key-points rail, pinned entries, and client polling that pauses on hidden tabs
and backs off on failure.

**PWA** — installable, offline fallback, network-first navigations, bounded
caches, update banner.

---

## Where uploads live

`uploads/YYYY/MM/<bucket>/<original-name>.<ext>`, where the bucket is the slug
of whatever the file was uploaded for:

```
uploads/2026/08/জলবায়ু-পরিবর্তনের-প্রভাব-মোকাবিলায়/98.png
uploads/2026/08/sports/seed-sports-1.jpg
uploads/2026/08/ads/creative.jpg
uploads/2026/08/avatars/me.jpg
uploads/2026/08/misc/98.png          ← no context: a media-library upload
```

The article editor posts the saved slug, or the headline currently in the box
when the article has not been saved yet; slugifying happens server-side in
`App\Support\Slug` so the Bangla rules live in one place. The original filename
is kept — it is what an editor recognises — and made unique within its folder
(`98.png`, `98-2.png`), since the folder now carries the uniqueness a random
24-character name used to.

Files uploaded before this layout sit directly in the date folder. They are left
alone; `Media::$folder` returns null for them and the media library falls back
to showing the filename.

---

## Replaced images are reaped

Changing an article's lead image or an ad's creative deletes the old one — media
row, original and every derivative — through `ImageService::release()`. Without
it each edit leaked six files and a row nothing would ever point at again.

It is reference counted, not unconditional, and that is not caution: two columns
point at `media` by id (`articles.image_id`, `gallery_images.media_id`) and both
are `nullOnDelete`, so reaping a row two articles share would silently blank the
other one's lead image. Nine further columns reach the same file by bare path —
`topics.image`, `live_entries.image`, `galleries.cover`, `gallery_images.path`,
`epapers.pdf`/`cover`, `epaper_pages.image`/`pdf`, `ads.asset`, `users.avatar` —
because most of those modules predate the media library.

Two details worth keeping:

- `release()` runs **after** the owning row is written, so the caller's own old
  value is already gone and does not read as a reference to itself.
- The reference check uses the query builder, not Eloquent. A soft-deleted
  article still references its image, and restoring one whose media had been
  reaped would leave it pointing at nothing.

`MediaSeeder` is unaffected: it relinks with query-builder updates rather than
going through the controller, so a re-seed never reaps.

---

## What the test pass found

Three defects, none of which a lint pass, a route listing or a manual
click-through would have surfaced. All three are fixed.

1. **Staff preview of a draft returned 500.** `ArticleController::isViewable()`
   deliberately lets staff see an unpublished story, and the article template
   then called `@bndate($article->published_at)` on it. A draft has no
   `published_at`, and `Bangla::date()` is typed against `CarbonInterface`, so
   the whole page threw a `TypeError`. The meta tags a few lines above used
   `?->` and were fine — only the visible byline was unguarded. The byline now
   shows `অপ্রকাশিত খসড়া` instead.

2. **Six templates leaked an output buffer on every request.**
   `@section('description', $page->meta_description)` is only the inline form
   when the value is not null; Blade compares with `===`, so a null switched it
   to the block form, opening a buffer that nothing ever closed. The page still
   rendered, which is why it survived. Found because PHPUnit flags an
   unbalanced buffer as a risky test — the assertion that pins it is
   `ob_get_level()` before and after the request. Full write-up in `CLAUDE.md`.

3. **Any reporter could delete any image in the shared media library.**
   `MediaController` had no `Gate::authorize()` on any method, against the
   convention that every public method of an admin controller authorises.
   Uploading and re-captioning are legitimately part of filing a story, so
   those stay open to all staff; `destroy` now requires `manage-taxonomy`,
   because `ImageService::delete()` removes the original and every derivative
   from disk and a reporter could otherwise strip the lead image off somebody
   else's published article with no way back.

Three more came out of covering the reader-facing half.

4. **Every comment "like" returned 500.** `Comment::likedBy()` declared
   `withTimestamps()`, but `comment_likes` has only `created_at` — stamped by
   the database with `useCurrent()` — and no `updated_at`. Every like therefore
   died on `Unknown column 'updated_at' in 'field list'`. The sibling
   `bookmarks` table carries a migration comment explaining this exact trap and
   the `bookmarks()` relation avoids it; `likedBy()` did not. It now declares
   `withPivot('created_at')` the same way. Verified over real HTTP against the
   seeded database: `{"liked":true,"count":1}` where it previously threw.

5. **Reply threads were never flattened.** `CommentRequest::after()` re-parents
   a reply-to-a-reply so threads stay one level deep — on a phone, which is
   most of this traffic, deeper nesting is unreadable. It did that with
   `$this->merge()`, which writes to the request's input bag, while the
   controller reads `$request->validated('parent_id')`, which comes from the
   validator's own data. The merge was inert and every reply kept its deep
   parent. Now `$validator->setValue()`.

6. **A poll vote could be cast with another poll's option.** `option_id` was
   validated with a bare `exists:poll_options,id`, unscoped. A crafted request
   wrote a vote row against a foreign option and incremented *this* poll's
   total, while no option's own count moved — so the total stopped equalling
   the sum of its options and every percentage on the results bar was wrong.
   The rule is now scoped to the poll. This is the same shape as the comment
   `parent_id` graft, which `CommentRequest` had already guarded against.

A seventh came out of covering the layout manager.

7. **A block could be moved between columns onto another block's position.**
   `reorder()` — the drag handle — was correct: it rewrites every posted id's
   `column` and `position` together, so a drag can never collide. But the
   per-block settings form carries a `column` select of its own, and
   `LayoutController::update()` wrote the new column while leaving `position`
   untouched. Moving a main-column block at position 1 into a sidebar that
   already had blocks at 0 and 1 left two rows sharing position 1, and
   `HomeBlock::active()` orders by `position` alone — so which of the two came
   first on the front page was whatever InnoDB returned that day. Nothing threw
   and nothing was logged; the page just came out in an order nobody chose.

   `update()` now appends to the end of the destination column, exactly as a
   drop into that column would. Both append paths go through one
   `nextPosition()` helper rather than repeating the expression, which also
   fixed a smaller disagreement: `store()` computed `(int) max(...) + 1`, and
   `max()` on an empty column returns null, so the first block added to an
   empty column started at position 1 while a reorder numbers from 0.

   The seeded layout was checked against the live database and is clean —
   contiguous and 0-based in both columns.

An eighth came out of covering the live blog.

8. **A burst of updates could be skipped by the polling client, for good.**
   The polling endpoint returns `entries` — capped at 30 — and `latest`, the
   cursor the client stores and sends back. `latest` was `max(id)` over the
   whole timeline while `entries` was the newest 30 by `published_at`. So if
   more than 30 updates landed between two polls — a goal, a verdict, a
   result, the minutes when a live blog is worth having — the client received
   30, advanced its cursor past all 35, and then only ever asked for ids above
   it. The ones in the gap were never sent and never asked for again.

   The two branches want opposite ends of the timeline, which is what the
   single query got wrong. A first load wants the *top* — pinned above newest —
   and does not care what is below the fold, because the server-rendered
   timeline already put it there. An incremental poll wants the *oldest*
   updates above the cursor, so a burst drains over successive round trips.
   Both now come back newest-first, which is the order the client prepends in,
   and the cursor may only advance as far as what was actually sent.

   Verified over real HTTP against a 35-entry blog: two polls, 30 then 4, each
   batch newest-first, cursor landing on the last id and stopping.

A ninth came out of covering the feeds, and it is the smallest of them.

9. **Every RSS enclosure announced itself as a JPEG.** The template carried a
   literal `type="image/jpeg"`, so a PNG or a WebP lead image went out with the
   wrong media type. An enclosure is a *typed* pointer and the feed offers no
   other way to find out what the file is, so a reader that trusts the
   declaration renders nothing rather than falling back to sniffing.

   Not hypothetical: `MediaSeeder` draws its section plates as PNG and one
   article on this box is carrying one. It only stayed invisible because that
   story is not currently in the newest forty.

   `Article::image_mime` derives it from the path, the same source
   `image_url` reads — `articles.image` is a bare path that may be an external
   URL or a legacy import with no media row behind it, so `media.mime` is not
   available for every case. Unknown extensions still say JPEG, which is the
   safe guess and what the old code assumed for everything.

Three further findings were in the tests themselves and are worth keeping. The
article factory's generated body is not inert corpus. `BanglaContent` injects one of
ten phrases as an `<h2>`, one being `জলবায়ু পরিবর্তনের প্রভাব মোকাবিলায়`, and the
full-text index covers `body` — so roughly one article in ten matched a search
for জলবায়ু by accident. Search fixtures pin `excerpt` and `body` for that
reason.

And `@example.com` cannot be used anywhere the `dns` validation rule applies.
The newsletter box validates `email:rfc,dns`, and egulias rejects the RFC 2606
reserved domains outright regardless of what DNS returns — so the obvious test
address is refused before it reaches the controller. `NewsletterTest` uses a
resolvable domain, which does mean those four tests need working DNS and about
150ms of real lookup each. Worth knowing that the rule puts a blocking DNS
lookup in front of every newsletter submission on the live site too.

And the e-paper's page order is delivered twice over, so the obvious test for
it does not test what it looks like it tests. Removing `->orderBy('page_number')`
from `Epaper::pages()` leaves every page still rendering in the right order,
because `unique(epaper_id, page_number)` is the index MySQL resolves the
`epaper_id = ?` lookup through and it returns the rows in that order anyway. A
*wrong* explicit order — `orderBy('id')` — does fail. `EpaperReaderTest` says
so in the test itself rather than leaving a future reader to assume the
assertion guards the clause.

The reader turned up no defects otherwise, which is worth stating plainly: it
was the last uncovered *public* surface, and it was already correct.

The OAuth pass turned up the most consequential of these, and it is not about
OAuth. **Strict mode does not catch a lazy load on a model fetched with
`first()` or `find()`.** `Builder::hydrate()` stamps the per-instance flag that
enforces `preventLazyLoading` only `if (count($items) > 1)` — so a single-row
result comes back with the guard *off* and `$one->relation` loads in silence.
`Model::preventsLazyLoading()` stays true throughout; it is the instance copy
that decides.

It was found because a test expected to fail did not:
`SocialiteController::resolveUser()` does
`SocialAccount::where(...)->first()` and then reads `->user`, which is textbook
lazy loading, and it works. The consequence is not a crash — it is that
`CLAUDE.md`'s first rule, the one the whole codebase leans on, covers less
than everybody assumes, and an N+1 on a single-row fetch will never be flagged
by a test, a local click-through or `HarnessTest`. That includes every
route-model-bound model and `Auth::user()`, both of which are single-row
fetches.

**Swept.** The hole was closed in vendor for a diagnostic run — one character,
`count($items) > 1` to `>= 1` — and the whole suite plus a crawl of every
parameterless GET route and a bound example of each parameterised one was run
against it. Three application sites and two test files came out, all fixed:

| Site | Relation |
|---|---|
| `Site\CategoryController::__invoke` | `children`, on every category page |
| `Site\PollController::results()` | `options`, on the already-voted branch |
| `Admin\LiveEntryController::update`/`destroy` | `article`, for the gate |

**None of them cost a query**, and that is worth stating rather than quietly
implying otherwise: a lazy load of one relation is one query and an eager load
of it is also one query — `where parent_id = ?` becomes
`where parent_id in (1)`. What the fixes buy is that the rule holds
everywhere, so the day one of those reads moves inside a loop the eager load is
already there.

**Then closed for good.** `AppServiceProvider::closeTheLazyLoadingHole()` sets
the flag from a wildcard `eloquent.retrieved` listener — that event fires from
`newFromBuilder()` for every hydrated row, before the `count()` check, so the
framework overwrites it with the same value when it does agree. Outside
production only, guarded on `Model::preventsLazyLoading()`, so production
registers no listener. `HarnessTest` pins it, and pins the exemption that keeps
every factory working: a `wasRecentlyCreated` model may still read a relation.

The cost is one property assignment per hydrated row — 23 on a cold homepage,
46 on a category page, and **0 on a warm homepage**, because the front page
comes back from `PackedCache` and never hydrates through the builder.

That last point is also the limit: `unserialize()` fires no `retrieved`, so
anything restored from the cache is still unguarded — `PackedCache` and plain
`Cache::remember()` alike. It means a page built from a cached payload cannot
demonstrate a violation at all, which matters when writing a test as much as
when reading one. The payloads are cached with their relations loaded, so
there is normally nothing to lazy-load; `HomepageCacheTest` and
`AdCreativeSizingTest` are what pin that.

Read the runner's `errors` as well as its `failures` if the sweep is ever
re-run by hand: two of the five findings were thrown outside a request and
arrived as errors, which is how the first pass missed them.

And a second, smaller one in the same family: **`LoginTest`'s session-rotation
test could not fail.** Laravel's test client does not carry a response's
cookies into the next call, so the two requests it compared were unrelated
sessions whose ids always differ — deleting
`AuthenticatedSessionController`'s `session()->regenerate()` left it green.

**Fixed.** `TestCase::continuingSession()` feeds the cookie back so the two
requests are one session, the test asks for a persisting store, and it asserts
a control first — two plain requests must keep the *same* id — because a
harness that silently stops carrying anything reports a rotation on every run.
Both fixation tests were then checked by removing every rotation on their path,
including `SessionGuard::updateSession()`'s own `regenerate(true)`, at which
point they fail.

That guard call is the part worth carrying forward: the framework rotates the
id on every login by itself, so a controller's explicit `regenerate()` is
belt-and-braces and a test that looks like it guards that line is really
guarding Laravel. `CLAUDE.md` has the shape.

---

## Known gaps

### Blocking a public launch

1. **Test coverage is started, not finished.** 756 tests cover both halves of
   the app: public routes, the admin authorisation matrix, the policies, Bangla
   search, comment moderation, the whole reader path from registration through
   bookmarks, history, comments, the newsletter and polls, what the three feeds
   actually say, the public e-paper reader, and OAuth sign-in. The three areas
   this item used to name are all done; what is left is depth rather than a
   missing surface.
2. ~~**Editor-written bodies are raw HTML rendered unescaped.**~~ **Done.**
   All three — `articles.body`, `live_entries.body` and `pages.body` — are
   still stored as HTML and still printed with `{!! !!}`, but nothing unsafe
   can reach them any more. Each model's `saving()` hook runs the body through
   `App\Support\Html`, an allow-list sanitiser built on PHP 8.4's HTML5
   parser, and `content:sanitize` brings stored rows forward. The live-blog
   timeline is covered on both of its readers: the server-rendered list and
   the JSON the polling client injects with `x-html`.
3. **Demo data and demo logins are in the database.** `demo:purge` now does
   this — it deletes the seeded content and every user except the admins, the
   three `@newspaper.test` logins included, and keeps the category tree, the
   homepage layout, the settings and the six static pages. **It has not been
   run here**, deliberately: this database is what manual verification uses.
   Run it on the deployment target, not on the development box.

   Two things it does not do, both by design. It leaves the sample imprint
   settings and the six page bodies alone — that is item 4 below, not this one
   — and it reports files left under `uploads/` rather than sweeping the
   directory blind.
4. ~~**Placeholder branding**~~ **Done, as a demo identity.** There is no real
   publication behind this install, so the branding is a *coherent fictional*
   one rather than a client's — and it says so, everywhere a reader might
   otherwise assume an organisation stands behind it. Nothing invents an
   address, a phone number, a company or an editor: those are legal details on
   a real masthead, and a plausible fake is worse than an obvious blank because
   it never gets corrected.

   Five things were wrong, and four of them would have shipped:

   - **The masthead was the name of a real newspaper.** `দৈনিক সংবাদ` is
     *The Sangbad* (সংবাদ) — founded 17 May 1951 in Dhaka, the oldest
     newspaper in Bangladesh, still publishing. Fine as a throwaway
     placeholder, not fine on something anybody looks at, where it reads as a
     clone of an outlet that exists.

     It is `দৈনিক আলোরেখা` now, **checked in August 2026** against web search
     in both Bangla and romanised form, the `allbanglanewspaper.org` and
     `w3newspapers.com` directories, and any news domain of that name. No hit
     on any of them.

     What that does not cover is the authoritative source. The DFP register
     holds roughly **3,176** publications and the aggregators between them list
     perhaps 150, all national; `dfp.portal.gov.bd` serves a default Kubernetes
     placeholder certificate, so it cannot be read over a connection worth
     trusting, and bypassing that to treat unverified bytes as a legal register
     would be worse than not checking. **So: no prominent collision, which is
     the bar that matters here, but not a clean registry check.** A district
     daily by this name could exist and be invisible to everything reachable.

     One thing worth knowing before any future rename: `আলো` is the most
     crowded stem in Bangladeshi mastheads — প্রথম আলো, কালের আলো, সময়ের আলো,
     যুগের আলো, একুশের আলো, আলোকিত বাংলাদেশ, আলোকিত সকাল — so a name built on
     it starts closer to the real ones than one that is not.
   - **The six static pages were `BanglaContent` filler** — the same generated
     noise as the 374 demo articles, on the six pages a reader opens *because*
     they want an answer. They are written now, in
     `Database\Seeders\Support\StaticPageContent`, and each says plainly that
     this is a demonstration.
   - **The imprint was plausible rather than blank**: `মোঃ আব্দুল করিম` and a
     real Dhaka media-district address, on fields a newspaper is legally
     required to publish. `demo:purge` deliberately keeps that group, so those
     values survived the pre-launch sweep by design. They now name themselves
     as demo values.
   - **`public/favicon.ico` was a zero-byte file** — an empty 200, which is
     not the same as a 404. Browsers still request `/favicon.ico` whatever the
     `<link rel="icon">` says, and they cache the nothing they got.
   - **The maskable icon was clipped.** The manifest declared `icon-512.png` as
     `purpose: maskable` while its content ran to 81% of the canvas. A maskable
     icon may only rely on a *circle* of 80% diameter, which is tighter than
     the 80% square people assume, so its corners came off on every circular
     launcher with nothing to say so.

   `brand:icons` draws the set from the brand colour — `any` and `maskable` as
   separate files, because they are different jobs — and writes a real
   multi-size `.ico`. They stay wordless on purpose: GD does no complex
   shaping, so Bangla conjuncts come out unformed, which is the same reason
   `EpaperSeeder` draws a nameplate-shaped block rather than a nameplate.

   The social links are blank rather than `https://facebook.com`; the footer
   already skipped empty values, and nothing was empty, so a full row of live
   buttons took readers to the front page of Facebook.

   `BrandingTest` pins all of it. Every assertion was checked by putting the
   old value back.
5. ~~**No backups, no deploy runbook.**~~ **Both exist.** The runbook is
   [`DEPLOY.md`](DEPLOY.md). `backup:run` dumps the database and archives
   `uploads/` — which is in neither the dump nor git — nightly at 03:00, and
   verifies both before reporting success. A truncated dump is a *valid* gzip
   file, so the check is mysqldump's own completion marker; anything that
   fails is deleted rather than left looking like a backup.

   ~~Two things keep this from being finished, and neither is a code
   change.~~ **Both are done.**

   - ~~**The artifacts sit on the same disk as the database they came
     from.**~~ `backup:sync` copies every verified artifact to any
     S3-compatible bucket, and `backup:run` calls it itself, so one schedule
     entry and one exit code mean "there is a checked backup and it is not
     only on this machine". It copies rather than streams, because a
     `mysqldump | gzip | aws s3 cp` pipeline cannot check its own completion
     marker and uploads truncated dumps with total confidence. A copy that
     cannot be proved is deleted from the remote — size always, S3's ETag
     under `--verify=checksum` where the upload was single-part, and the whole
     object streamed back and hashed under `--verify=download`.
   - ~~**Nothing monitors it.**~~ Two failures, and only one of them can be
     reported from the box. A backup that *breaks* now goes out through
     `ErrorAlerter`, which is what `config/errors.php` said it was for all
     along and nothing had wired. A backup that *never runs* — cron entry
     deleted, box powered off, disk full at midnight — reports nothing,
     because nothing runs; `BACKUP_HEARTBEAT_URL` is pinged only after a run
     completes and verifies, so an external service alerts on the ping that
     never came. `/fail` is pinged on a failed run, so one switch covers both.

   Off-site is off when `BACKUP_OFFSITE_BUCKET` is blank, and the nightly run
   still succeeds — an install that has not configured it is not failing its
   cron every night to say so. The cost of that choice: a mistyped bucket name
   reads as "not configured", which is why `backup:sync` prints the disk it
   looked at and `DEPLOY.md` says to run it once by hand.

   **One thing is still open, and it is the one that matters.** None of this
   has run against a real bucket. `league/flysystem-aws-s3-v3` is installed,
   `backups_offsite` is configured, and the whole path is exercised end to end
   in tests and by hand — but against a *local directory* standing in for the
   remote. What that proves is this command's contract: what it uploads, what
   it skips, what it refuses to leave in place. What it cannot prove is the
   half that only a real endpoint has — credentials, region and endpoint
   resolution, path-style addressing, bucket policy, and the multipart upload
   that a 79 MB uploads archive will actually take. The multipart path is the
   interesting one, because it is the case where the ETag stops being an MD5
   and `verify()` falls back to size.

   So: treat off-site as **built but unproven** until somebody runs it against
   the destination it will really use. The step is small — put the six
   `BACKUP_OFFSITE_*` values in `.env`, then:

   ```bash
   php artisan backup:sync --dry-run             # names what would go up
   php artisan backup:sync --verify=download     # proves the bytes
   php artisan backup:sync                       # must skip everything
   ```

   Scope the key to **write-only on that one bucket**: a key that can delete
   the bucket turns one compromised web server into no backups. And set
   `BACKUP_OFFSITE_PREFIX` if that bucket will ever take more than one server,
   or two boxes write the same object key on the same night.

   ~~**Error tracking is still open.**~~ **Done.** Every reportable exception
   is written to `storage/logs/errors-YYYY-MM-DD.log` as JSON, and the first
   occurrence of each distinct fault pushes an alert to email, a webhook, or
   both — throttled to one per fault per hour under a ceiling of twenty an
   hour, because a thousand alerts is indistinguishable from silence.
   `errors:digest` mails a grouped summary at 07:00, which is how the faults
   the throttle silenced still get seen. Both are off until `.env` names a
   destination.

   What is *not* covered from inside the box: if mail delivery breaks, the
   alert about it goes to the same broken mail. The complements both sit
   outside it — the backup heartbeat above, and an external uptime check
   against `/up`.

   ~~`/up` is Laravel's stock health route.~~ It is a real check now.
   `App\Listeners\DiagnoseHealth` runs `select 1`, a cache write and read
   back, and a writability test on `storage/logs` and `storage/framework`; a
   throw turns the 200 into a 500 — the body is Laravel's stock HTML page, so
   a monitor has to match on the status code, not on it. Both docs claimed a
   `{"status":"up"}` / `{"status":"down"}` JSON body until this was checked;
   there is none, and the *healthy* page contains the literal string
   `status-down` as a CSS class, so the obvious keyword check is red from the
   first poll. The stock route
   answers 200 as soon as the framework boots, so a monitor watching it would
   have sat green through a dead database. SMTP, push and the backup bucket
   are deliberately *not* checked — all three can be down for an hour without
   a reader noticing, and a check that goes red while the site is fine trains
   everyone to ignore it.

### Decisions the runbook surfaced

- ~~**`config/app.php` sets `'timezone' => 'UTC'`.**~~ **Fixed.** It is
  `Asia/Dhaka` now, with `DB_TIMEZONE=+06:00` pinned to match. This turned out
  to be a bug rather than a preference: MySQL here runs with a system zone of
  `+06` while PHP ran on UTC, so `bookmarks` and `comment_likes` — which stamp
  `created_at` with `useCurrent()`, MySQL's clock — were six hours apart from
  every row PHP stamped. It also fixes the admin's scheduling inputs, which are
  wall clock: an editor asking for 3 PM was getting 9 PM.
- ~~**Scheduled articles never publish themselves.**~~ **Fixed.**
  `articles:publish-due` runs every minute and moves a `scheduled` article to
  `published` once its time has passed, then clears the homepage cache. It
  does exactly what the admin's status flip does and nothing more, so the cron
  and the button cannot drift apart, and it leaves `published_at` alone —
  that is the time the editor chose, not the second cron got round to it.
  The dashboard's `scheduled_due` count now means "cron is not running"
  rather than "somebody needs to press a button".

### Feature-incomplete

### The rate-limit review

Before it, five routes were throttled — all of them in `auth.php` — out of 61
that change state. Sixty are now covered; `logout` is the deliberate exception.
The numbers and the reasoning are in [`DEPLOY.md`](DEPLOY.md).

One thing the review turned up and did not fix, noted there: there is no
global limiter on the `web` group, because volumetric limiting belongs at the
proxy. ~~The other — a 429 rendering Laravel's stock English page — is now
fixed: the application ships a full set of Bangla error pages.~~

### ~~Known inaccuracy~~ — fixed

**`categories.articles_count` and `users.articles_count` no longer drift.**
`Article::booted()` maintains them the way `Comment::booted()` has always
maintained `comments_count`: one hook shape covering publish, unpublish, a move
between categories, a reassigned byline, trashing and restoring, in any
combination.

`counters:recompute` is the reconcile behind it, at 02:45 before the backup so
what gets dumped is corrected data. It exists for what events cannot see — a
bulk `whereIn()->update()`, an import, a restored backup, or a bug in the hooks
themselves — and it reports how many rows were wrong *before* fixing them. A
nightly run that always says nothing says the hooks are working; the first
night it speaks up, they are not.

`ContentSeeder` now calls that command instead of carrying its own copy of the
UPDATEs. Two definitions of a correct count is how the thing that fixes drift
comes to disagree about what drift is.

### Feature-incomplete

6. **Galleries and e-paper still have no imagery.** ~~No images exist to
   serve.~~ `MediaSeeder` now draws a section-coloured plate library, runs it
   through `ImageService`, and links all 374 articles, so the ladder renders
   and Lighthouse is unblocked.

   ~~Two caveats on the generated plates. They are abstract compositions, not
   photographs.~~ **Superseded for articles.** All 374 now carry real
   photojournalism: 99 Prothom Alo frames imported with `photos:import` and
   spread deterministically across the table. The plates were only ever a
   stand-in for this, and the caveats they carried — useless for judging a
   crop or a focal point, lower rungs too lean for an honest LCP — no longer
   apply to any article page.

   Two things the real photographs changed, both worth knowing:

   - **The ladder stops at w960.** The sources are ~1380px wide and
     `ImageService` will not upscale, so `rungsFor()` produces w320 through
     w960 and no w1600. The article hero asks for 1600 and gets 960. That is
     correct behaviour on these files, not a regression, but it means the hero
     is no longer measuring the top rung — larger sources would restore it.
   - **The stored original is heavier.** A photograph at quality 88 lands
     between 200 and 500 KB where a flat plate was ~45 KB. Only browsers
     without WebP ever fetch it, but it is the `src` fallback, so it is the
     number that matters if that ever stops being a rounding error.

   Both modules have an admin now (gaps 8 and 9), `EpaperSeeder` fills
   `/epaper` with six drawn back issues, and `GallerySeeder` fills `/photo`
   with seven — six published and one draft. Nothing is left rendering an
   empty hub.
6b. ~~**The seeded plate library is gone from this box, and the ad slots point
   at nothing.**~~ **Fixed.** Found while importing the photographs, and it
   predated that import — `photos:import` only inserts media and updates
   articles, and the six admin uploads sharing the table survived untouched.
   The `media` table held no `seed-*` rows and
   `storage/app/public/uploads/2026/08/ads/` did not exist, while all five
   house ads still carried `uploads/2026/08/ads/seed-ad-*.jpg` in `asset`, so
   every slot rendered a broken image.

   Repairing it meant fixing `MediaSeeder` first. It relinked *every* article
   on every run, so the obvious repair — re-seed the imagery — would have
   replaced all 374 photographs. It now assigns only to articles whose lead
   image is genuinely absent: a null `image_id`, a deleted media row, or a
   path with no file. The plate library is drawn lazily behind that check, so
   a run that only repairs the ads costs about a second rather than the minute
   54 plates take.

   `db:seed --class=MediaSeeder` then filled all five slots, left all 374
   photographs alone, and drew nothing. Verified over HTTP: every ad slot on
   the homepage serves a real creative, all 200. `MediaSeederTest` pins both
   halves — what it heals and what it refuses to touch.

9b. **`/epaper` has demo issues; `/photo` still does not.** `EpaperSeeder`
   draws six back issues of eight pages each — masthead, column rules,
   headline bars, a picture block — and files them through `ImageService`, so
   the ladder and the grid thumbnails are real rather than stubbed. About 38
   seconds cold and nothing on a re-run: an issue is looked up by the
   `(date, edition)` pair the table already makes unique, so a hand-made issue
   sitting on one of those dates is left exactly as it is.

   The pages are drawn rather than photographed on purpose. What has to read at
   the 200px the grid shows is the *shape* of a newspaper; a photograph in a
   page slot reads as a mistake. The masthead carries no words, because GD does
   no complex shaping and would set Bangla with its conjuncts unformed and its
   vowel signs out of order — a wrong nameplate in front of a Bangla newsroom
   being worse than a nameplate-shaped block.

9c. ~~**Galleries have no equivalent seeder.**~~ **Done.** `GallerySeeder`
   fills `/photo` with seven galleries — six published, which is exactly what
   the homepage's active `photo_row` block shows, and one draft, which must
   appear in the admin list and nowhere public.

   It is the one imagery seeder that **draws nothing and stores no files**. A
   gallery is curation — the desk choosing from photography that already
   exists — so the seeder takes the road the admin's attach-from-library
   button takes: existing `media` rows copied onto `gallery_images` as a
   `media_id` plus a denormalised `path`. 62 row inserts, about half a second,
   and the galleries come out exactly as good as the site's imagery is. Where
   `photos:import` has run they are real photojournalism; on a fresh `db:seed`
   they are the same plates every article is showing.

   Three things worth carrying forward:

   - **The pool is restricted to imagery seeding owns** — the `photos/` import
     and the `seed-*` plates, minus the ad creatives. An editor's own upload
     sitting in the media library is not demo material, and a seeder that
     published one into a demo gallery is a surprise nobody would notice until
     after the fact. E-paper pages are excluded by the same rule: a broadsheet
     page in a photo gallery reads as a mistake.
   - **The caption is the seeder's; the credit is the photograph's.** Inventing
     provenance for someone else's frame is not a demo detail worth having, so
     `credit` is copied off the media row — a plate says it is symbolic, a
     photograph says who took it. `উৎসবের রং` is deliberately left uncaptioned,
     because credit-without-caption is the normal state of a gallery just
     filled and is the exact combination that rendered as nothing until
     `photo-show` was fixed.
   - **Images are strided, not dealt in runs.** Consecutive frames in an import
     are often minutes apart at the same event, so dealing gallery-by-gallery
     would stack one event into one gallery. Striding by the gallery count
     spreads them, deterministically and with no PRNG — `SeedImagery` documents
     why nothing here may call `mt_srand()`, and index arithmetic sidesteps the
     question. A fresh box curates 62 slots out of 54 plates, so wrapping is
     the normal case rather than an edge one: galleries share images, but a
     gallery never attaches the same one twice.

   `GallerySeederTest` pins all of it, including the fresh-box pool size.

7. ~~**Push notifications are half-present.**~~ **Finished.** The handlers the
   service worker already had now have everything behind them:
   `minishlink/web-push`, a `push_subscriptions` table, `config/push.php`,
   `PushService`, subscribe/unsubscribe endpoints, an Alpine store and toggle,
   `push:keys`, `push:send`, and a button in the article editor.

   The shape of it, and the decisions worth not re-litigating:

   - **Guests subscribe.** Most readers of a news site are not signed in, and
     breaking news is exactly what they want a notification for. The browser's
     own permission prompt is the consent record; `user_id` is a label a row
     acquires if somebody happens to be signed in on that browser. Identity is
     the `endpoint`, so one browser is one row for ever and re-subscribing
     updates rather than accumulates.
   - **Two controls, because they are two things.** The account's
     `breaking_alerts` checkbox is its standing answer and now finally does
     something — switching it off stands every one of that reader's browsers
     down. It cannot switch them back *on*, because only a browser grants the
     permission, which is why the preferences screen carries a per-browser
     toggle beside it. The public one lives in the footer: quiet and always
     reachable, rather than a permission dialog on arrival, which is how a
     reader learns to say no once and for ever.
   - **Sending is never automatic.** `is_breaking` is a display flag that
     drives the ticker and gets toggled while writing. A push cannot be
     recalled, so it is a command an operator runs and a button an editor
     presses — authorised on `publish`, since reaching every reader on the site
     is at least as consequential as putting the story on it, and guarded by
     `articles.push_sent_at` so the same story cannot go out twice.
     `push:send --dry-run` prints the audience and the exact payload and sends
     nothing, which is the only way to read a notification before several
     thousand people do.
   - **A 410 deletes the row.** A push service saying the browser is gone is
     the system working, not a failure; continuing to send is what gets a
     sender blocked. `PushResult` counts pruned separately from failed.

   Three things that are *not* done, all deliberate:

   - **Only breaking news is sent.** `push_subscriptions.breaking` is a column
     rather than an implication so that wiring the existing
     `followed_categories` preference to a per-category alert is a write, not a
     migration.
   - **There is no send log.** `push_sent_at` records that a story went out and
     the command reports the counts, but nothing keeps who pressed it or how
     many arrived. A `push_notifications` audit table is the obvious next piece
     if a desk ever needs to answer for one.
   - **This box has neither GMP nor BCMath**, so every encryption runs on
     web-push's pure-PHP fallback. It works — verified end to end — but a real
     subscriber list wants one of those extensions installed; `DEPLOY.md` says
     so.
8. ~~**E-paper has no upload UI.**~~ **Done.** `Admin\EpaperController` plus two
   screens, the same shape as galleries: a list with a create form, and an
   editor with drag page ordering, multi-file page upload, a whole-issue PDF,
   per-page section labels and delete. Editions come from
   `config('site.epaper_editions')` and are unique per date.

   The sharp edge is `unique(epaper_id, page_number)`. Writing a new order
   straight out collides the moment two pages swap — setting page 2 to 1 while
   a page 1 still exists is a duplicate key, not a reordering. Renumbering runs
   in two passes inside a transaction, parking pages in a 100+ scratch band
   that is free by construction because `MAX_PAGES` is 99 and the column is an
   unsigned tinyint. Deleting a page closes the gap the same way, so the next
   upload does not land on a hole.

   Two things worth carrying forward:

   - **The upload limits on this box cannot carry a real page scan.**
     `upload_max_filesize` is 2M here while the controller validates 12 MB
     pages and 50 MB PDFs. PHP discards an oversize upload before Laravel
     runs, so the rule that fires is `uploaded`, not `max` — the messages now
     say so in Bangla, and `DEPLOY.md` carries the ini block the e-paper
     actually needs. Nothing in the code can work around it.
   - ~~**The public reader resolves an issue by date alone.**~~ **Done.**
     `/epaper/{date}` took the first published row for that date, so a second
     edition was unreachable from the front end even though the admin could
     create one — and *which* one a reader got was whatever InnoDB returned
     from an unordered `firstOrFail()`.

     `?edition=` names one. Three things about the shape it took:

     - **The bare date is not "any of them".** It resolves to the house
       edition — the first key of `site.epaper_editions` — and falls back to
       the alphabetically first only on a day the house edition skipped. That
       half matters even on a paper that will only ever run one edition,
       because "unordered" is not a behaviour anybody chose.
     - **The house edition's URLs did not change.** `Epaper::url` omits the
       parameter for it, so every existing link keeps its shape and a
       single-edition install never sees a query string. An explicit
       `?edition=main` is the same paper at a second address and answers 301,
       the way `ArticleController` treats a stale slug.
     - **An address nothing links to is only half a fix.** The header names
       every edition published that day, and the back-issue rail is scoped to
       the edition being read — unscoped it listed each date twice with both
       links leading to the same issue. The admin's preview link had the same
       bug and now uses `Epaper::url` too.

     `EpaperReaderTest` covers it in thirteen tests, each checked by mutating
     the thing it guards. One of them could not fail when first written:
     `assertSee('barisal')` for an unlabelled edition's pill matched the
     string inside its own `?edition=barisal` href, so it was green with no
     label rendered at all. It asserts the anchor's text now.
9. ~~**Photo galleries have no admin CRUD.**~~ **Done.** `Admin\GalleryController`
   plus two screens: a list with a create form, and an editor with drag
   ordering, multi-file upload, attach-from-library, per-image caption and
   credit, and delete. Authorised with `manage-taxonomy` — editor and up —
   rather than a policy of its own, because a gallery is curation more than
   reporting and sits alongside topics and the front-page layout. If reporters
   ever need to file their own, the upgrade is a `GalleryPolicy` shaped like
   `ArticlePolicy`; nothing in the controller assumes otherwise.

   `/photo` is no longer an empty hub — it renders whatever the desk publishes.

   Three things the build turned up, all fixed:

   - **`release()` has to run after the rows are gone.** Deleting a gallery
     released each image's file first and deleted the rows second, so every
     file was still a reference to itself and nothing was ever freed. The rows
     did disappear, which is what made it look like it worked; only the disk
     said otherwise. Found by deleting a real gallery over HTTP and counting
     files, not by a test — the tests were asserting rows.
   - **A credit with no caption rendered as nothing.** `photo-show` nested the
     credit inside `@if ($image->caption)`. The upload form takes one credit
     for a whole batch and captions one at a time, so "credited, uncaptioned"
     is the normal state of a gallery just filled.
   - **`galleries.images_count` was never maintained.** Every reader goes
     through `withCount('images')`, which sets an attribute of the same name
     and shadows the column, so a permanently-zero column was invisible. It is
     now kept by `GalleryImage::booted()` the way `Comment::booted()` keeps
     `comments_count`, and reconciled by `counters:recompute`.
10. ~~**Newsletter never sends.**~~ **Done** — and it turned out to be two
    gaps, not one. `store()` had always told the reader to check their inbox
    while the double opt-in mail was a `TODO(Phase 3)`, so a footer subscriber
    could never reach `verified_at` and `active()` never matched them. The send
    list was, in practice, empty. Both halves are built now:
    `NewsletterVerify`, `NewsletterDigest`, `NewsletterService`,
    `newsletter:send`, HTML and plain-text templates, and two scheduler entries
    (daily 07:30, weekly Friday 08:00).

    What shapes it:

    - **Nothing is queued, because no worker runs.** `QUEUE_CONNECTION=database`
      and nothing calls `queue:work`, so a `ShouldQueue` mailable would sit in
      the `jobs` table looking exactly like a send that worked. The opt-in mail
      goes inline from the request — throttled to five an hour, which is what
      makes SMTP latency there acceptable — and the digest inline from cron,
      where blocking is the point. `ErrorAlerter` made the same call.
    - **A quiet news day sends nothing.** An edition that comes back empty skips
      that reader entirely and deliberately does *not* stamp `last_sent_at` —
      they are still due whenever there is something to say. A newsletter that
      arrives every morning regardless is a newsletter that gets filtered, and
      after that none of them arrive.
    - **Editorial before algorithmic.** The desk's lead or feature leads the
      email; the rest is ordered by what readers actually opened. Ordering
      purely by views leads on whatever went viral, which is not the same thing
      as whatever mattered.
    - **`last_sent_at` is stamped per subscriber as each send succeeds**, not
      batched at the end — a run that dies at row 2,000 of 4,000 must not
      re-mail the first 2,000. One rejected address is caught and logged
      individually, so it cannot cost the rest of the list their edition.
    - **Editions are memoised per category signature.** Most readers ask for
      everything, so the general edition is built once; only the distinct
      *sets* of followed categories cost another query, rather than one per
      subscriber.

    Two things it does *not* do, both deliberate: there is no queue (see above,
    and `DEPLOY.md` says what to do when the list outgrows an inline send), and
    no open or click tracking — a tracking pixel is a third-party request in a
    reader's inbox and was not worth adding for a number nobody had asked for.

10b. **Unsubscribing used to happen on GET.** Found while building the digest,
    and pre-existing: `GET /newsletter/unsubscribe/{token}` unsubscribed on
    sight, and Gmail, Outlook and every corporate mail scanner fetch the links
    in a message before a human sees it. Readers were being unsubscribed by
    machines, silently, with no signal anywhere.

    The link now renders a confirmation and the button posts. One-click
    unsubscribe from the mail client's own chrome (RFC 8058
    `List-Unsubscribe-Post`) reaches the POST directly, answers 200 with no
    body, and is CSRF-exempt because it arrives with no session — the
    64-character token in the URL is the credential, and the only thing that
    request can do is stop that address receiving mail.

    Those headers are not decoration. A bulk sender without them gets throttled
    on reputation alone, and a reader who cannot find the unsubscribe link
    presses "spam" instead — which is the one signal there is no recovering
    from.
11. **Bilingual is scaffolded, not built.** `locale` and `translation_of`
    columns exist and slugs are unique per locale, but there is no English
    edition, no language switcher and no `hreflang`.
12. ~~**`redirects` table is unused.**~~ **Done — and it is not middleware,
    which is what this entry and `PLAN.md` both assumed.**
    `App\Services\RedirectResolver` hangs off the 404, registered as an
    exception render callback in `bootstrap/app.php`.

    Two things rule middleware out. `/{category}` is constrained `.*`, so it
    swallows every depth of path — `/2019/05/old-story.html` matches
    `category.show` and 404s from *inside* the controller. Nothing reaches a
    routing-level 404, so a `Route::fallback()` never fires and middleware
    would have to run before the router to see anything. And running before
    the router means a lookup on every request to the site, for ever, against
    a table that is empty on every install that never migrated a CMS. Hooking
    the 404 costs nothing on a request that resolves — asserted, not assumed:
    `RedirectTest` counts the queries — and one indexed lookup on a request
    that was already lost.

    The ordering that falls out is also the right one: **a live page always
    wins over a rule recorded for the same path.** A rule that could shadow
    real content would be invisible until somebody noticed the wrong page.

    `php artisan redirects:import mapping.csv` is the writer, because the
    shape of this job is a file of thousands of rows exported from the system
    being replaced, not a form filled in one URL at a time. `--dry-run`
    reports without writing. Re-importing corrects destinations and keeps
    `hits`, which is what tells an operator a year later which rules still
    carry traffic and which can go.

    **One limitation, and it is the one that matters for a real migration.**
    `/{category}/{id}/{slug}` takes any numeric middle segment, so a
    WordPress-style dated permalink — `/2019/05/old-story` — matches
    `article.show` with article id 5. If article 5 exists the page never
    404s, the rule never runs, and the reader is canonicalised to a story
    with nothing to do with the one they asked for. That is a property of
    this site's URL scheme rather than anything a redirect table can fix, so
    `redirects:import` **names every rule that will never fire** — two batch
    queries whatever the size of the file — while somebody still has the
    mapping file open. Found by probing over HTTP, not by the suite: the rule
    sat at zero hits and nothing said why.

### Smaller

13. ~~Scheduled articles need a cron entry (`status=scheduled` past its
    `published_at` is counted on the dashboard but never auto-publishes).~~
    **Done**, and had been for a while — this entry was simply never struck
    through. `articles:publish-due` runs every minute from
    `routes/console.php`, and §"Decisions the runbook surfaced" above has
    described it as fixed the whole time.
14. ~~Ad impressions are never incremented — only clicks are.~~ **Done.**
    `impressions` sat at zero for the life of the project while `clicks` was
    maintained, so every CTR the admin showed was 0.0% by construction.

    **Counted by the browser, not by the server**, and that is the whole
    design decision. Incrementing while building the page would have been one
    line and wrong in both directions: creatives are `loading="lazy"`, so a
    slot below the fold is frequently never fetched — measuring the front page
    for CLS found only one of its three slots had loaded by the end of the run
    — while every crawler that fetched the HTML would have counted as a
    reader. An advertiser paying per impression would be billed the second
    number for the first.

    So `ad-impressions.js` applies the rule the industry uses — half the
    creative in view for one continuous second — and posts every slot that
    qualified in **one beacon per page**, which the endpoint turns into **one
    query** with a single `whereIn(...)->increment()`. That matters on a front
    page that is otherwise eight queries warm.

    Three things worth knowing:

    - **`live()` scopes the increment.** A tab left open keeps its ids; a
      creative paused or past its end date is not still earning impressions.
    - **The list is capped at ten.** The ids are client-supplied, so without a
      bound one POST is a write to every row in the table. The rate limiter
      (`throttle:ads`, 20/minute) is the rest of that answer.
    - **It cannot prove the browser was honest**, and nothing client-reported
      can. A server-side render count would be just as forgeable — by loading
      the page — and wrong on top of it.

    Found while wiring it: **every seeded house ad was a link to a 404.**
    `click()` aborts on an ad with no `url` and all six have none, but the
    slot wrapped every creative in that link regardless. It only links when
    there is somewhere to go now.

    The JavaScript has no automated coverage — there is no JS test runner in
    this repo and adding one for a single module was not worth it — but the
    state machine was driven through a stubbed-browser harness: the dwell
    timer, a slot that leaves before its second is up, one beacon per page, an
    id counted once however often it re-enters, and a page with no filled slot
    sending nothing.
15. ~~**Ad creatives are tracked now, but still served at full size.**~~
    **Done.** Uploads have gone through `ImageService` for a while, so every
    creative already had a WebP ladder — but `ads` kept only `asset`, a bare
    path, and the slot rendered that. The ladder existed and nothing could
    reach it, so a phone loading the front page was sent a 970px billboard.

    `ads.media_id` is the link, the same shape as `articles.image_id` beside
    `articles.image`: the id carries the derivatives, the path stays because it
    is what an external creative or a pre-media-library import has.
    `nullOnDelete`, because a media row can be shared and reaping one must
    blank the link rather than take the ad with it. The migration backfills
    from `media.path = ads.asset`, which linked five of the six seeded
    creatives; the sixth is the untracked one this gap already named, and it
    correctly stayed null and still renders.

    Measured on the live box: the billboard now offers w320/w640/w768/w960
    with `sizes="(max-width: 970px) 100vw, 970px"`, against one 970px JPEG
    before.

    Two things worth keeping:

    - **A single-rung srcset is emitted on purpose.** `ImageService` will not
      upscale, so a 300×250 rectangle produces exactly one rung — the common
      case for the small slots, not an edge one. The usual advice against a
      one-candidate srcset is about stopping a browser reaching for something
      larger, and there is nothing larger; what there is, is WebP where the
      fallback is JPEG. Measured on the seeded sidebar creative: **6.5 KB
      against 16.2 KB** for the same pixels. `Article::imageSrcset`'s docblock
      claimed the opposite and had claimed it since it was written — the code
      never did that, and the comment is corrected.
    - **The slot keeps its reserved box.** CLS is zero because `width`,
      `height` and the `aspect-ratio` are set whether or not a creative has
      arrived, and a test pins that srcset did not cost it.

16. `ArticleQuery::related()` runs a correlated `EXISTS` subquery for topic
    matching; fine at this size, worth watching as content grows.
17. ~~**Cold homepage is ~1.4s / 80 queries.**~~ **Done.** 93 queries to 48 and
    417ms to 291ms at the median; warm went 17 queries to 8, of which none are
    content — they are the cache store and the session and nothing else.
    Rendered bytes are identical before and after on the homepage and on a
    category page, which is the only real proof that a change like this changed
    nothing.

    The 1.4s in the original note was largely a cold InnoDB buffer pool rather
    than the application: the first request after a restart measured 2.8s here
    and every repeat measured 380ms with the same 93 queries. Query count is
    the number worth tracking on this box; wall-clock swings by a factor of
    three between byte-identical runs.

    Three things came out of it, all fixed:

    - **`LayoutComposer` ran four times per request**, once per view it is
      bound to, re-reading the same three cache keys each time: twelve round
      trips to the `cache` table on every request on the site, warm or cold,
      for three distinct values. It is `scoped` now and memoises them.
    - **Every block eager-loaded its own cards.** A dozen independent lists on
      one page meant a dozen `with()` passes over the same handful of sections,
      bylines and photographs — 36 queries where 3 will do. The lists are built
      with `ArticleQuery::deferred()` and hydrated once at the end.
    - **The cached payload was 555 KB**, and `layout.categories` another 106 KB,
      both pulled out of MySQL on *every* request. See item 19.

18. ~~**`SeedImagery::__construct()` overflows its seed on about one image in
    five.**~~ **Fixed.** `($seed * 2654435761) & 0xFFFFFFFF` computed the mask
    after the multiply, and PHP has no unsigned int to hold the result: any
    `crc32()` above ~3.47e9 — 330 of 2,113 probed seeds, 15.6% — pushed the
    product past `PHP_INT_MAX`, so PHP promoted it to a float and dropped
    exactly the low bits the mask exists to keep, raising *"Implicit
    conversion from float … to int loses precision"* on the way past.

    The hash is now applied in 16-bit halves, which is the same value modulo
    2^32 with every intermediate inside an int. Checked against
    arbitrary-precision references over the whole 32-bit range: 2,113 seeds,
    zero mismatches, no deprecation under `E_ALL`.

    Two things worth knowing about the fix:

    - **It was never only a notice.** A deprecation is fatal wherever they are
      promoted to exceptions, which is how it was found — a `migrate:fresh
      --seed` driven through `tinker` aborted partway through the plate
      library, after 19 files.
    - **The 15.6% that overflowed now draw different images**, because they
      were being drawn from a corrupted seed before. Nothing on disk changes:
      both imagery seeders are idempotent, so nothing is redrawn in place, and
      determinism is unaffected — the same seed still draws the same image, it
      is simply the image that was always intended.

    `Unit/SeedImageryTest` pins the arithmetic against those references and
    asserts no seed raises the deprecation.

19. ~~**The cached payloads were 660 KB per request.**~~ **Done.** Serialized
    Eloquent graphs are mostly not content: `original` repeats `attributes`
    verbatim on every model, and the same twenty property names appear on each
    of a few hundred objects. That shape compresses about as well as
    anything can.

    `App\Support\PackedCache` stores them zlib-compressed and base64-encoded —
    base64 because `cache.value` is a `mediumtext` in `utf8mb4` and compressed
    output is binary, which MySQL rejects. `homepage.blocks` goes 555 KB to
    41 KB, `layout.categories` 106 KB to 6 KB, and the total read per request
    679 KB to 65 KB.

    It is not a trade. Reading a packed entry back is *faster* — 6.4ms against
    7.5ms — because inflating 41 KB and parsing the result beats parsing 555 KB
    of serialize text. The compress costs 10ms and happens once per TTL.

    Two things to know. `serializable_classes` still applies: the unserialize
    moved into `PackedCache`, which reads the same config key, so there is one
    list and not two. And **a rollback past this needs `cache:clear`** — old
    code reading a packed entry gets a string where it expects an array. The
    forward direction is safe by construction, and so is a corrupt row: both
    read as a miss and rebuild. `HomepageCacheTest` pins that.

20. ~~**Three OAuth behaviours are pinned as they are, not as they read.**~~
    **Fixed**, all three — and the decision behind the second of them turned
    out to be holding down a 500 on the public site.

    - **An unverified provider email no longer claims a verification nobody
      performed.** Case 2 always refused to *link* one to a local account;
      case 3 went on creating one and stamping `email_verified_at` anyway,
      because the check reads `if ($user && ! verified)` and there is no
      `$user` to protect. Sign-up still works — refusing it outright would
      lock out anyone whose provider simply does not send the flag — but the
      account is created unstamped and `Registered` fires, so the reader gets
      the same verification mail a form registration sends. That is what gates
      commenting, so nothing is lost and nothing is invented.

      The event is dispatched from `callback()` rather than inside
      `resolveUser()`'s transaction: nothing can unsend a message a rollback
      decided never happened.

    - **A deleted reader is told the truth.** Deletion stays permanent — the
      account page promises exactly that — so signing in again resurrects
      nothing. It used to fall through to *"this social account has no
      email"* and send them off to a registration that could not succeed
      either, because the soft-deleted row still holds the unique index on
      the address. Both lookups now run `withTrashed()` and hand the trashed
      user back, and the controller says so in Bangla.

      The second lookup mattered more than it looks: a *different* provider
      identity on a deleted reader's address reached `User::create()` and
      became a duplicate-key 500, not a refusal.

    - **The stored avatar is refreshed.** `resolveUser()` returns at case 1
      for every returning reader, so the `updateOrCreate()` below it was only
      ever reached when no row existed — it always created and never updated,
      and the avatar was written once and never touched again. Case 1 now
      updates it, and only when it differs, so an ordinary sign-in stays a
      read.

    **What the second one uncovered.** Account deletion is a soft delete so
    that a reader's published comments stay attributable — that is what the
    comment in `ProfileController::destroy()` says it is for. It was not true.
    `Comment::user()` had no `withTrashed()`, so the relation went null the
    moment anybody deleted their account, and `comment/item.blade.php` reads
    `$comment->user->avatar_url` unguarded: every article page carrying one of
    their approved comments threw. Reproduced on the seeded box —
    `ErrorException: Attempt to read property "avatar_url" on null` in
    `laravel.log`, gone after the fix, byline rendering again.

    `Article::author()` is deliberately left alone: every byline template
    guards `@if ($article->author)`, so a deleted staff account drops the
    byline rather than taking the page with it. Both answers are correct and
    the difference is now written down in `CLAUDE.md`.

    `AccountProfileTest` asserts the claim the deletion test only made in a
    comment.

---

## Next up (Phase 6)

In rough order of value:

1. ~~Wire `srcset`/`sizes` through `ArticleCard` and the article hero.~~ **Done**
   — also the gallery grid. No longer inert: the homepage renders 39 responsive
   images and the article hero its full four-rung ladder.
2. ~~Generate or import real seed imagery so the ladder is exercised.~~
   **Done** — `database/seeders/MediaSeeder.php`, drawn by
   `Support/SeedImagery.php`. Idempotent: re-running relinks against the
   existing library rather than redrawing (~90s cold, <1s warm).
3. Serve AVIF alongside WebP where the source justifies it. **Blocked on the
   box, not the code:** this PHP has no `imageavif()` and no Imagick, so
   `ImageService` cannot write AVIF here at all. Needs a rebuilt GD or
   `php-imagick` before the code is worth writing.

   The `w768` rung this item was paired with is **done**. Adding it surfaced
   that a rung change is inert on everything already stored — srcset is built
   from the `conversions` a row recorded, not from `WIDTHS`. `ImageService`
   gained `regenerate()`, `rungsFor()` and `hasCurrentLadder()`, the last being
   the single place that decides whether a row is behind. `php artisan
   media:backfill` brings real uploads forward (`--dry-run`, `--force`,
   `--chunk`); `MediaSeeder` asks the same question for the demo library. Both
   skip rows already current, so a no-op run costs one query per chunk.
4. ~~Lighthouse pass on homepage and article, mobile profile. Target ≥ 90.~~
   **Done** — see below.
5. ~~Measure Core Web Vitals against the budget in `PLAN.md`
   (LCP < 2.5s, CLS < 0.1).~~ **Done** — LCP 1.8–2.2s, CLS 0.000 on every run.
   The `width`/`height` on the article hero did what it was added for.
6. ~~Reduce cold-homepage query count.~~ **Done** — 93 to 48, and the cached
   payload 555 KB to 41 KB. See gaps 17 and 19.
7. `hreflang` + canonical review once a second locale exists.

Two things the Lighthouse pass surfaced. Both were described here as
decisions rather than bugs, and both turned out to be neither — the first was
real and is fixed, the second was simply untrue.

- ~~**Author avatars are third-party.**~~ **Done, and not by seeding.**
  `User::avatar_url` fell back to `ui-avatars.com` for anyone without an
  uploaded photograph — 36 of the 37 users here — so every author page and
  every comment thread sent a reader's IP address and the page they were on
  to a service nobody chose, for something purely decorative.

  Seeding local avatars, which is what this entry proposed, would have fixed
  the demo box and left every real reader who signs up and never uploads one
  still hitting that host. The *fallback* was the leak. It is drawn locally
  now by `App\Support\Avatar`.

  **An SVG data URI rather than a drawn PNG**, and that is the whole reason
  this is not another `SeedImagery`. GD does no complex shaping — the reason
  `brand:icons` and `EpaperSeeder` are wordless — but an SVG is shaped by the
  *browser*, so `বার্তা সম্পাদক` gets a legible `বস`. Verified in Chrome at
  88, 40 and 32px, the three sizes the site asks for.

  Two things it buys beyond the point of it: no request at all, and it is
  vector — `ui-avatars` returned a 64×64 PNG that the author page was
  rendering at 88. The cost is ~460 bytes of inline markup per face, which
  gzip removes almost entirely.

  It also fixed something adjacent: the author page published that generated
  fallback as `Person.image` in its JSON-LD, so a third-party URL stood as
  the canonical photograph of a staff member who had never uploaded one.
  `avatar_photo_url` returns null there instead, and `array_filter` drops the
  key — no photograph rather than a drawn one.
- ~~**Seeded ads are inactive.**~~ **This was wrong, and it mattered.** The
  claim was that all six ship `is_active = 0` so every slot renders its
  placeholder. `SiteSeeder` creates them with `is_active => true`, and this
  box has six ads, six active, six live. There is no flag to flip.

  Worth stating rather than quietly deleting: it means the Lighthouse and CLS
  figures below were measured against **filled** slots, not placeholders —
  which is the harder case and the one worth having, but not what this said.
  The same wrong sentence was in a `MediaSeeder` docblock and is corrected
  there too.

Phase 7 started ahead of the remaining Phase 6 items, and with the cold
homepage done there is now nothing unblocked left in Phase 6 at all: AVIF needs
a rebuilt GD or `php-imagick` on this box, and `hreflang` needs a second locale
to exist. Off-site backups and the health endpoint are done too, and branding was
closed as the fictional demo identity that declares itself — see gap 4 — so
nothing in Phase 7 is open on this side of a credential. The three test areas
that stood open alongside it are done.

The uptime check itself is the one piece that cannot live here: `/up` now fails
when a dependency does, and something outside this machine has to be watching
it. Same for the backup heartbeat. Both are a URL and a schedule on somebody
else's box, and `DEPLOY.md` says which.

---

## Lighthouse, 25 August 2026

Mobile profile, Lighthouse 12, simulated throttling. Medians of repeated runs.

Ads are live in every slot (`is_active = 1`, creatives from `MediaSeeder`).

| | Perf | A11y | Best Practices | SEO | LCP | CLS | TBT |
|---|---|---|---|---|---|---|---|
| Homepage | 95–99 | **100** | 100 | 100 | 2.1 s | 0.000 | 40–200 ms |
| Article | 95–99 | **100** | 100 | 100 | 2.0 s | 0.000 | 40–210 ms |

Performance is quoted as a range because this box is a working desktop:
PhpStorm, Chrome and Brave keep the load average near 4 on 8 cores, and TBT
swings between 40 ms and 210 ms across byte-identical runs. Before and after the
accessibility fixes the payload was the same 223 KB over 15 requests, so treat
the spread as machine noise and re-measure on an idle machine before quoting a
single number.

Both clear the ≥ 90 target and the CWV budget. Turning the ads on cost nothing
measurable, and neither did the accessibility fixes — the built CSS grew 0.1 KB
gzipped and the request count did not change.

### CLS with ads, measured properly

Lighthouse's 0.000 was not enough to call the ad slots proven. It does not
scroll, and the creatives are `loading="lazy"`, so only 1 of 3 homepage slots
ever loaded during a run — the other two were still empty boxes when CLS was
recorded. A separate Puppeteer harness loads each page, walks it in
viewport-sized steps until every lazy creative has arrived, and attributes each
`layout-shift` entry to the element that moved.

With all three slots loaded on both pages: **zero layout-shift entries.** Not a
small CLS — no shift events at all.

That number is only worth reporting because the harness was checked against a
control: injecting a 220px block at the top of the document while the viewport
is at the top registers 0.2673. The first version of the control injected after
scrolling to the bottom, where a top-of-page insert moves nothing on screen, and
so reported a false 0.0000 — a broken instrument reads exactly like a page with
no shift.

**Measure against a server that compresses, or the number is meaningless.** The
first run scored 83, and the entire gap was `Content-Encoding`. PHP's built-in
server sends none, and uncompressed the homepage document is 276 KB and the
built CSS 83 KB — Lighthouse charged ~1.6s for "enable text compression" and
LCP came out at 3.8s instead of 1.9s. Nothing about the application changed
between the two runs. The vhost in `docs/newspaper.local.conf` now carries the
`mod_deflate` and cache-header blocks; to reproduce without installing it, put
a router in front of the dev server that gzips text responses and run PHP with
`-d zlib.output_compression=1`.

Worth knowing about the run itself: it used the built assets with `APP_DEBUG=false`
and warm caches, but `APP_ENV` stayed `local`, so `Model::shouldBeStrict()` was
still active. `APP_ENV=production` would have been more representative and is
*not* usable here — `AppServiceProvider` calls `URL::forceScheme('https')` in
production, which breaks a plain-HTTP dev server.

### What it found, and what was done

Nothing performance-related in the application, and three real accessibility
defects. All three are fixed; a11y is 100 on both pages.

1. **`text-muted` on `bg-surface-2` was 4.34:1**, against the 4.5:1 that 13px
   text requires. A token pairing, so it failed wherever that combination
   appeared, not in one component. `--color-muted` moves from `#6B7280` to
   `#616874`, which lands at 5.05:1 on `surface-2`, 5.28 on `canvas` and 5.61
   on `surface`. A full matrix of every semantic foreground against every
   semantic background was checked in both themes: this was the only failing
   pair, and `muted` on `canvas` was next closest at 4.55. Dark mode passed
   throughout and is unchanged.
2. **Opinion-block links were 16–22px tall**, against a 24px minimum tap
   target. `opinion-row` now pads both links and pulls the space back with a
   negative margin, so the targets measure 30px and 28px while the card keeps
   its shape.
3. **The article's font-size buttons failed WCAG 2.5.3** — `aria-label="ফন্ট বড়
   করুন"` did not contain the visible `অ+`, so speech control could not address
   the button by what it says. The labels now lead with the visible glyph. The
   same control on the account preferences screen had no label at all and got
   the same treatment.

Two smaller diagnostics. The homepage ships 1,329 DOM elements. And the article
hero was flagged for 26 KB of oversize, which turned out **not** to be a `sizes`
defect: Lighthouse emulates 412 CSS px at DPR 1.75, so the hero needs 721 device
px, and with rungs at 640 and 960 the browser correctly took 960 rather than
upscale a 640. The ladder's spacing was the cause. **Fixed** — `w768` is now a
rung, the hero fetches 28 KB where it fetched 51 KB, and it no longer appears in
"properly size images" at all.

`SiteSeeder` now creates the house ads active rather than inactive. They were
switched off because there was no creative to show; `MediaSeeder` fills `asset`,
and leaving them off meant a fresh seed could not reproduce the CLS-with-ads
result recorded in `PLAN.md`.

---

## Resuming work

```bash
cd /var/www/html/newspaper
git checkout main
php artisan serve --port=8899        # or use the Apache vhost
```

Read [`CLAUDE.md`](../CLAUDE.md) first — it lists the traps that have already
cost time once.

### Where this was left

Phase 6 has nothing unblocked left in it. AVIF needs a rebuilt GD or
`php-imagick` on the box, and `hreflang` needs a second locale to exist —
neither is a code problem.

Picking up, in the order they are worth doing:

1. **Point the off-site backup at a real bucket** and run the three commands
   in gap 5 above. It is maybe ten minutes and it is the only thing standing
   between "built" and "working", on the feature whose entire job is to be
   there on the worst day.
2. **Create the two external watchers.** Everything on this side of them is
   done and proven; what is left is two objects in a Better Stack account and
   a public hostname to point one of them at, neither of which can live here.
   `DEPLOY.md` → "Setting the two watchers up" is a copy-paste procedure with
   the exact intervals and grace periods.

   The heartbeat half was run end to end against a real HTTP endpoint rather
   than a fake — a local listener standing in for the service, the same way
   `backup:sync` was proven against a local directory. A good run requests
   `/ping/<token>` and exits 0; a run with the database credentials broken
   requests `/ping/<token>/fail`, exits 1, and deletes the truncated dump
   instead of leaving it looking like a backup. `/up` was checked the way a
   monitor sees it — `APP_DEBUG=false`, 200 healthy and 500 with the database
   on a dead port — because with debug on the route rethrows and shows you
   something a monitor never sees.

   What that does *not* prove is the half only the service has: that the token
   is right, and that somebody is actually watching. Run
   `php artisan backup:run` once by hand after setting `BACKUP_HEARTBEAT_URL`
   and watch the heartbeat go green; a switch that was never armed looks
   exactly like a switch that has nothing to report.
**Nothing else is outstanding in the codebase.** Both items above need
credentials or a hostname; neither needs code.

Both of the smaller self-contained items are now closed: `/epaper/{date}`
reaching only one edition per day (gap 8), and the `redirects` table nothing
read (gap 12). **Nothing unblocked remains in the codebase.**

### What the last pass changed

Written down because most of it was found the same way, and that way is worth
copying: **breaking something to check the test could see it.** Green was not
evidence until a mutation proved it, and several of these were tests that had
been passing without ever being able to fail.

Coverage closed the three areas this file had listed as missing — feed
*contents*, the public e-paper reader, and OAuth sign-in — taking the suite
from 590 to 670.

**Eight defects in the application.** The RSS enclosure that announced every
image as a JPEG; deleting an account throwing on every article page carrying
one of that reader's comments; a second provider identity on a deleted
reader's address 500ing on a duplicate key; OAuth stamping `email_verified_at`
on addresses no provider had verified; a stored social avatar written once and
never refreshed; and three single-row lazy loads.

**Five in the branding.** A masthead matching a real newspaper, an imprint that
read as real, six pages of generated filler, a zero-byte `favicon.ico`, and a
maskable icon whose corners were clipped.

**Four in the harness itself**, and those are the ones worth remembering:

- **`LoginTest`'s session-rotation test could not fail.** Laravel's test client
  does not carry cookies between calls, so the two ids it compared were always
  different. It now carries the cookie and asserts a control first.
- **Strict mode never covered single-row fetches.** `Builder::hydrate()` sets
  the enforcing flag only when a query returned more than one row, so
  `first()`, `find()`, route-model binding and `Auth::user()` all lazy-loaded
  in silence. Closed permanently in `AppServiceProvider`.
- **Two tests lazy-loaded after `fresh()`**, invisible for the same reason.
- **One assertion in `BrandingTest` checked nothing** once the values it looped
  over were all blank — PHPUnit flagged it risky, which is the only reason it
  was noticed.

And two things were verified rather than assumed: the backup heartbeat has now
been run against a real HTTP endpoint on both its success and failure paths,
and the masthead has been checked against every public list of Bangladeshi
newspapers that can be reached — with the limits of that check written down
next to it, because "we checked it" is the part that survives retelling.
