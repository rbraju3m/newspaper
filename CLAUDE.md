# Working in this repo

A Bangla-first newspaper platform on Laravel 13 + Blade + Tailwind 4 + Alpine.
Read [`docs/STATUS.md`](docs/STATUS.md) for where things stand,
[`docs/DECISIONS.md`](docs/DECISIONS.md) for why things are shaped the way they
are, and [`docs/DEPLOY.md`](docs/DEPLOY.md) before putting it on a server —
PHP 8.4 is a hard floor, the driver must be MySQL, and `db:seed` writes demo
logins.

---

## Traps that have already cost time

Each of these shipped broken once. They are not hypothetical.

### Route order

`auth.php` is `require`d from the **top** of `web.php`. The catch-all
`/{category}` and `/{category}/{id}/{slug}` routes are registered **last**.
Anything added after them is unreachable; `/login` resolves as a category slug
if `auth.php` moves.

`php artisan route:list` sorts alphabetically — it does **not** show match
order. Verify ordering by matching a `Request` against the route collection.

### Strict mode is on

`Model::shouldBeStrict()` outside production. This means:

- **No lazy loading.** `$category->parent` in a factory, `$option->poll` in an
  accessor — both throw. Eager-load, or guard with `relationLoaded()`.
- **No mass-assigning guarded attributes.** `email_verified_at`,
  `moderated_by`, `moderated_at`, every `*_count` — use `forceFill()` or a
  query-builder `update()`.
- **No reading attributes a model never loaded.** This bites tests rather than
  requests: `User::factory()->create()` returns a model holding only the
  attributes the factory set, so `avatar_url` throws where a user hydrated from
  the row returns null. `->fresh()` anything you hand to `actingAs()`.

Before adding a `create()`/`update()`/`fill()` call, check the model's
`$fillable`. This bug class appeared five times.

### Cached models need allow-listing

`config/cache.php` → `serializable_classes` is an explicit list. Adding a model
to a cached payload without adding it there produces a `TypeError` on the *next*
request, not the one that wrote it.

### `getOriginal()` applies casts

Inside model events, `getOriginal('status')` returns the **enum**, not the
stored string. Compare against the enum, or use `getRawOriginal()`.

### MySQL reorders JSON object keys

A `json`-cast column does not come back in the order it went in — MySQL stores
objects sorted by key length, then value. `conversions` is written
`w320, w640, w768, w960, w1600, thumb` and reads back
`w320, w640, w768, w960, thumb, w1600`.

Nothing that reads by key cares, but `assertSame()` on the array fails on
ordering alone while every key and value matches. Use `assertEquals()`, which
compares arrays with `==` and ignores order.

### `@section('x', $value)` is only inline when `$value` is not null

Blade compares with `===`. A null second argument — an empty
`meta_description`, a topic with no blurb, an author with no bio — silently
switches the directive to its **block** form: `startSection()` opens an output
buffer and waits for an `@endsection` the template never writes.

The page still renders, which is why this survived every manual check. What it
leaves behind is an unbalanced output buffer on every such request, and a
`description` section that swallows whatever is emitted after it. Six templates
shipped this way.

Give every inline `@section` a `?? ''`. `PublicRoutesTest` asserts
`ob_get_level()` is unchanged across a request built with all optional text
nulled — that is the only cheap way to see it.

### FULLTEXT is invisible inside `RefreshDatabase`

InnoDB updates a FULLTEXT index at **COMMIT**. `RefreshDatabase` wraps each test
in a transaction it rolls back, so a row created in the test is findable by
`LIKE` and invisible to `MATCH ... AGAINST` in the same breath:

```
transactionLevel=1  rows=1  fts_hits=0  like_hits=1
```

Anything testing `Article::search()` must use `DatabaseTruncation` instead.
`SearchTest` does. Get this wrong and the relevance assertions return nothing
while every `assertDontSee` goes green against an empty result set.

Related: the factory's generated body is **not** inert corpus. `BanglaContent`
injects one of ten phrases as an `<h2>`, and one of them is
`জলবায়ু পরিবর্তনের প্রভাব মোকাবিলায়`. Since the index covers `body`, about one
article in ten matched a search for জলবায়ু by accident — a test that failed on
roughly a tenth of runs. Search fixtures must pin `excerpt` and `body`.

### `merge()` inside `after()` never reaches `validated()`

`FormRequest::merge()` writes to the request's **input bag**. `validated()`
reads back from the **validator's own data**. A `merge()` in a `prepareForValidation()`
hook works because it runs before the validator collects; a `merge()` inside an
`after()` closure does not, and fails silently.

`CommentRequest` re-parents a reply-to-a-reply this way and the flattening
never happened. Use `$validator->setValue('key', $value)` — `validated()`
re-reads `getData()`, so that does land.

### Pivot tables here have `created_at` and no `updated_at`

`bookmarks`, `comment_likes` and friends stamp `created_at` with `useCurrent()`
and deliberately carry no `updated_at`. A relation declaring `withTimestamps()`
therefore writes a column that does not exist and every attach dies on
`Unknown column 'updated_at' in 'field list'`.

`Comment::likedBy()` shipped that way and every comment like was a 500. Declare
`->withPivot('created_at')`, the way `User::bookmarks()` does.

### `exists:` without a scope accepts a foreign row

`'option_id' => ['exists:poll_options,id']` accepts an option belonging to a
*different* poll. The vote is written, this poll's total is incremented, and no
option's own count moves — the total stops equalling the sum of its options and
every percentage is wrong.

Scope it: `Rule::exists('poll_options', 'id')->where('poll_id', $poll->id)`.
`CommentRequest` guards `parent_id` against the same graft by hand.

### `@example.com` fails the `dns` rule

egulias rejects the RFC 2606 reserved domains (`example.com/.org/.net`, `.test`,
`.invalid`, `.localhost`) regardless of what DNS returns, so any test address
hitting `email:rfc,dns` is refused before the controller sees it. The newsletter
box is the one endpoint that uses it. `NewsletterTest` uses a resolvable domain
and therefore needs working DNS — and that rule puts a blocking ~150ms lookup in
front of every live submission too.

### Two ways to change a block's column

The front page layout manager has a drag handle *and* a `column` select in each
block's settings form. `LayoutController::reorder()` handles the drag and is
safe by construction: it rewrites `column` and `position` together for every id
posted, so it renumbers 0..n-1 per column and cannot collide.

`update()` is the other door, and it used to write the new `column` while
leaving `position` alone — which drops the block onto whatever already holds
that index in the destination. `HomeBlock::active()` orders by `position` and
nothing else, so a tie is broken by whatever InnoDB returns. Nothing throws;
the front page simply comes out in an order nobody chose.

Both append paths now go through `nextPosition()`. Anything that gives a block
a column must give it a position in the same breath.

### Bulk updates skip model events

`Comment::whereIn(...)->update()` will not fire the counter hooks. The admin's
bulk moderation deliberately saves row by row, and so does
`articles:publish-due`.

Four counters are maintained this way — `comments_count` in
`Comment::booted()`, and `categories.articles_count` /
`users.articles_count` in `Article::booted()`. Any write that goes round
Eloquent leaves them wrong.

`counters:recompute` is the reconcile, nightly at 02:45 and safe to run by
hand. It prints how many rows were wrong before correcting them, which is the
number worth watching: it should be zero every night, and the first night it
is not, a hook is broken. `ContentSeeder` calls it rather than repeating the
UPDATEs.

Decrements are guarded with `where('articles_count', '>', 0)`. The column is
`unsignedInteger`, so decrementing zero is an out-of-range *error* under strict
mode — a drifted counter would become a 500 rather than a wrong number.

### Bangla slugs need `\p{M}`

The character class is `[^\p{L}\p{M}\p{N}\s-]`. Drop `\p{M}` and vowel signs and
hasant are stripped: `ক্রিকেট` → `করকট`, and distinct tags collide.

### Alpine plugin order

Stores calling `Alpine.$persist()` must import `resources/js/bootstrap.js`, not
`alpinejs` directly. ES imports are hoisted, so a store importing Alpine
directly runs before `Alpine.plugin(persist)`.

### `hover:` is gated behind a media query

Tailwind 4 wraps every `hover:` and `group-hover:` variant in
`@media (hover: hover)`:

```css
@media (hover:hover){ .group-hover\:opacity-100:is(:where(.group):hover *){opacity:1} }
```

A control that only appears on hover therefore **does not exist on a touch
device**, and headless Chrome reports `hover: none` too — so it cannot be
verified by screenshot either. `el.matches(':hover')` returns true while the
descendant's computed style never changes, which reads exactly like a broken
selector.

Pair every hover-revealed control with `group-focus-within:` and
`[@media(hover:none)]:` so it stays reachable by keyboard and by finger.

### Editor HTML is sanitised on write, not on read

Three columns are printed with `{!! !!}` — `articles.body`,
`live_entries.body` and `pages.body`. That is safe only because nothing unsafe
can reach them: each model's `saving()` hook runs the body through
`App\Support\Html::sanitize()` on every write, so the stored value is already
clean. `live_entries.body` has a second reader — the polling endpoint hands it
to `x-html`, which is `innerHTML`, where a `<script>` is inert but
`<img onerror>` is not.

Three things follow.

**A fourth body means a fourth hook.** Anything new that gets printed with
`{!! !!}` needs the same `saving()` guard *and* an entry in
`SanitizeContentBodies::TARGETS`, on the day it starts being rendered.

**Do not add a second way to write a body.** A query-builder `update()` on any
of those columns skips model events and puts raw markup straight into something
printed unescaped. `content:sanitize` exists to clean up after exactly that, and
is the *only* place in the app allowed to do it.

**Widening the allow-list is a two-sided change.** `Html::ALLOWED` and
`.prose-editorial` in `resources/css/app.css` have to agree, or you get markup
that survives sanitising and renders unstyled. After widening, run
`php artisan content:sanitize` — stored bodies were cleaned against the *old*
list and nothing re-cleans them on its own.

`<iframe>` is the one allowed element that executes code, so it is allow-listed
by **host** (`Html::EMBED_HOSTS`) rather than by scheme. `class` is filtered to
`lat` and `tabular` — an arbitrary class escapes the semantic tokens.

The parser is PHP 8.4's `Dom\HTMLDocument`, not `DOMDocument`. The old HTML4
parser disagrees with browsers about foreign content (`<svg>`, `<math>`), which
is where every mutation-XSS bug lives, and it hands back numeric entities on
some input — `&#2476;` where বাংলা used to be, in a column FULLTEXT covers.

### `demo:purge` keeps the shell, not the content

`demo:purge` is the pre-launch sweep: it deletes the seeded articles, comments,
tags, topics, media, ads and polls, and every user except the admins — the three
`@newspaper.test` logins included, because a known address whose password is the
word "password" is the whole point of the exercise.

It **keeps** the category tree, the homepage layout, the settings and the six
static pages, so what is left is an empty newspaper rather than a blank
database. That is the difference between it and `migrate:fresh --seed`, which is
not a substitute in either direction.

Three things it is easy to get wrong when editing it:

- **Media has to go through `ImageService::delete()` before the table sweep.**
  A row's derivatives live in its `conversions` column, so deleting the row
  first strands the whole ladder on disk. This shipped broken for an hour and
  `DemoPurgeTest` is why it did not ship at all.
- **A new table needs adding to `PurgeDemoData::PURGE`.** Most of it would
  cascade from `articles` and `users` anyway; the list is explicit so a table
  added later reads as one the command does not know about, rather than as rows
  that quietly survive.
- **It refuses to delete every user.** Ending a purge with nobody who can sign
  in is not recoverable short of tinker.

Safe by default: it prompts unless given `--force`, and `--dry-run` prints the
whole plan and changes nothing. `--keep=a@b.com` preserves an account that is
not an admin.

### PHP and MySQL are two clocks

`APP_TIMEZONE=Asia/Dhaka` and the mysql connection's `'timezone' => '+06:00'`
are two halves of one setting. MySQL converts every `TIMESTAMP` column on the
way in and out using the **session** zone, so changing one without the other
puts the application and its own database six hours apart.

That is not hypothetical — it is what was wrong. MySQL here runs with a system
zone of `+06` while PHP ran on UTC, and `bookmarks` and `comment_likes` stamp
`created_at` with `useCurrent()`, which is MySQL's clock. Two rows written in
the same instant were six hours apart, and nothing said so.

The cheap check, whenever timestamps look wrong:

```php
now()->toDateTimeString();                       // PHP's clock
DB::select('SELECT NOW() n')[0]->n;              // MySQL's
DB::select('SELECT @@session.time_zone s')[0]->s;
```

They must agree. `DB_TIMEZONE` is pinned rather than inherited from the
server's system zone so this cannot vary with the box.

Also: the admin's `datetime-local` inputs and the date archive are **wall
clock**, not instants. They are the reason this is a behaviour bug and not a
formatting one — an editor asking for 3 PM was getting 9 PM.

### Response return types

`Illuminate\Http\Response` is **not** a supertype of `JsonResponse` or
`RedirectResponse`. A controller that can return either needs
`Symfony\Component\HttpFoundation\Response`.

### `nullable` omits absent keys

`$validated['category_id']` is an undefined index when the field was not
submitted at all. Use `?? null`.

### Nested forms are invalid HTML

Per-row action buttons inside a bulk-select form must target a separate form via
the HTML5 `form="id"` attribute. Do not rely on duplicate `_method` fields
resolving by DOM order.

---

## Conventions

### Bangla in the UI

All reader- and admin-facing strings are Bangla. Use the Blade directives rather
than calling the helper:

```blade
@bn($count)          {{-- ১২৩৪ --}}
@bndate($date)       {{-- ২৫ আগস্ট ২০২৬ --}}
@bntime($date)       {{-- রাত ৯:৪৫ --}}
@bnago($date)        {{-- ৩৮ মিনিট আগে --}}
@bncount($views)     {{-- ১২.৪ হাজার --}}
@bnfulldate()        {{-- মঙ্গলবার, ২৫ আগস্ট ২০২৬, ১০ ভাদ্র ১৪৩৩ বঙ্গাব্দ --}}
```

Wrap Latin runs (numerals, IDs, timestamps) in `class="lat"` so they render in
Inter with tabular figures.

### Styling

Use the **semantic** tokens, never raw palette values:

`bg-surface` `bg-canvas` `bg-surface-2` `text-ink` `text-body` `text-muted`
`border-line` `border-line-strong` `text-brand` `text-link` `shadow-card`
`shadow-pop`

They are defined light-first in `resources/css/app.css` and redefined under
`.dark`. A hard-coded colour will not survive a theme switch.

Ad slots must use `<x-ui.ad-slot>` so the box is reserved and CLS stays at zero.

### Queries

Listing pages use `ArticleQuery::cards()` — it selects only the columns a card
renders and eager-loads category and author. Never `SELECT *` into a listing;
`body` is a `longText`.

### Cache busting

After changing anything an editor controls, clear what depends on it:

```php
HomepageService::flush();          // publishing, layout blocks
Cache::forget('layout.categories'); // category edits
Cache::forget('layout.trending');   // topic edits
AdService::flush();                 // ad edits
Setting::flush();                   // settings (also clears the per-request memo)
```

### Error pages come in two families

`resources/views/errors/` splits deliberately. `404`, `403`, `419`, `429` and
`4xx` extend `layouts.site`, because at those codes the application is healthy
and the reader should keep the nav.

`500`, `503` and `5xx` extend `errors/standalone.blade.php` and must stay that
way: no layout, no composers, no database, no `@vite`, inline CSS. The site
layout's header and footer are built by view composers that query the category
tree, and `CACHE_STORE` is the database — at 500 none of it can be relied on,
and a layout that throws while rendering an error page loses the error page.
`artisan down` also pre-renders 503 to a static file served without booting the
app, so an asset reference there would break on the next build.

If you touch the 5xx views, re-run the check that proves it: render them with
`config(['database.connections.mysql.port' => 1])` and `DB::purge('mysql')`.
`ErrorPageTest` does exactly that, and restores the port in a `finally` — a
dead connection left behind fails the *next* test instead.

### Rate limits

Named limiters live in `AppServiceProvider::registerRateLimiters()` and are
applied as `throttle:<name>` in the route files. Add a state-changing route,
give it one — `php artisan route:list --json` plus a filter on `method` is how
the gaps were found the first time.

Authenticated limiters key on **user id**, not IP. A newsroom is behind one
NAT, so an IP bucket puts the whole desk in one editor's allowance.

A limit a real person can hit is a bug. Anything needing a limit that tight
belongs in the controller, where it can say so in Bangla — `CommentController`
refuses a second comment inside a minute that way. The middleware is only there
to stop the request that never should have arrived.

### Authorisation

Every public method of an admin controller authorises. Gates:

- `manage-site` — ads, pages, users, settings (admin only)
- `manage-taxonomy` — categories, tags, topics, layout (editor and up)
- `ArticlePolicy` — per-article; reporters cannot publish, place or reassign
- `CommentPolicy::moderate` — editors and up

Hiding a nav link is not access control.

---

## Verifying a change

`php artisan test` runs and passes — 406 tests, ~103s. Behaviour coverage exists
for both halves of the app:

| File | Covers |
|---|---|
| `HarnessTest` | the harness itself — driver, FULLTEXT index, strict mode |
| `PublicRoutesTest` | every public URL, canonicalisation, draft visibility, feeds, output-buffer balance |
| `AdminAuthorizationTest` | the role matrix, by URL, plus publish/edit/delete actions |
| `Unit/PolicyTest` | the decision tables, including CommentPolicy's edit window |
| `SearchTest` | Bangla `MATCH ... AGAINST`, filters, LIKE fallback |
| `CommentModerationTest` | moderation and the denormalised `comments_count` |
| `RegistrationTest`, `LoginTest`, `EmailVerificationTest`, `PasswordResetTest` | the whole auth path, including phone login in both digit systems |
| `AccountProfileTest` | profile, password, deletion, preferences, newsletter sync |
| `BookmarkTest`, `ReadingHistoryTest` | saved stories and reading progress |
| `CommentPostingTest` | the reader side of comments, both abuse controls |
| `NewsletterTest`, `PollVotingTest` | subscribe/verify/unsubscribe, and poll voting |
| `ResponsiveImageTest`, `MediaBackfillTest`, `ArticleImageSyncTest`, `AdAssetTest`, `MediaUploadTest` | the imagery ladder |
| `Unit/HtmlSanitizerTest` | the HTML allow-list — the vector table, and that cleaning is idempotent |
| `ContentSanitizeTest` | that every write path applies it — articles, live entries, pages — and `content:sanitize` |
| `DemoPurgeTest` | `demo:purge` — what it deletes, what it keeps, and that it will not lock you out |
| `BackupTest` | `backup:run` — that the dump holds rows, not just schema, and that a bad one is discarded |
| `ErrorAlertTest` | error alerting — the throttle, the hourly cap, and that a failing channel never escapes |
| `ScheduledPublishingTest` | `articles:publish-due` — that a due story becomes readable and a future one does not |
| `ArticleCounterTest` | the denormalised article counts through every transition, and the nightly reconcile |
| `RateLimitTest` | every named limiter, its headroom, and that authenticated buckets are per-account |
| `ErrorPageTest` | the Bangla error pages, and that the 5xx ones render with no database |
| `LayoutReorderTest` | the front-page layout manager — drags within and across columns, the cache flush, and that a column change cannot collide |

Still uncovered: the live blog append path, feed *contents* as opposed to
well-formedness, the e-paper reader, and OAuth sign-in.

Tests run on **MySQL**, against `newspaper_test`, not SQLite in-memory:
`Article::search()` silently falls back to `LIKE` on any non-MySQL driver, so
the `MATCH ... AGAINST` path that production uses would never be exercised.
`pdo_sqlite` is not installed here in any case.

`tests/TestCase.php` refuses to run unless the connected database name ends in
`_test`. `RefreshDatabase` drops every table it finds, and an exported
`DB_DATABASE` **overrides** `phpunit.xml` — PHPUnit does not force-override an
existing environment variable. Without that guard, one stray export wipes the
seeded demo database. Do not remove it.

If the test database is ever lost, recreate it with MySQL root (`-uroot`; the
`newspaper` user is scoped to `newspaper`.* and cannot create databases, and
`sudo mysql` socket auth is not configured on this box):

```bash
mysql -uroot -p -e "CREATE DATABASE newspaper_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -uroot -p -e "GRANT ALL PRIVILEGES ON newspaper_test.* TO 'newspaper'@'127.0.0.1';"
```

Beyond the suite, at minimum:

```bash
find app database routes config -name '*.php' -exec php -l {} \;
php artisan view:clear && php artisan view:cache && php artisan view:clear
npm run build
php artisan route:list --except-vendor
```

Then exercise the actual path with `curl` against a running server, logged in as
the relevant role, and check `storage/logs/laravel.log` is clean. Compile-time
checks have repeatedly passed while the runtime path was broken — the database
and the browser are where the real bugs surfaced.

---

## Local environment

- Project root `/var/www/html/newspaper`, Apache docroot `/var/www/html`
- Global `AllowOverride None` — Laravel needs its own vhost
  (`docs/newspaper.local.conf`)
- Apache runs as `www-data`; `storage/` and `bootstrap/cache/` are ACL-granted
- Credentials live in `.env` (git-ignored). Never read another project's `.env`
  to obtain them — ask.
- **`.env` on this box points `MAIL_MAILER` at a real Gmail account**, not at
  `log`. Anything that calls `Mail::` from tinker or a scratch script sends a
  real message from a real address. Use `Mail::fake()` in tests — the suite
  does — and leave mail out of manual probes, or set `MAIL_MAILER=log` first.
  This bit once: a probe of the error-alerting path sent live mail to a
  made-up address.
