# Working in this repo

A Bangla-first newspaper platform on Laravel 13 + Blade + Tailwind 4 + Alpine.
Read [`docs/STATUS.md`](docs/STATUS.md) for where things stand and
[`docs/DECISIONS.md`](docs/DECISIONS.md) for why things are shaped the way they
are.

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

### Bulk updates skip model events

`Comment::whereIn(...)->update()` will not fire the counter hooks. The admin's
bulk moderation deliberately saves row by row.

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

### Authorisation

Every public method of an admin controller authorises. Gates:

- `manage-site` — ads, pages, users, settings (admin only)
- `manage-taxonomy` — categories, tags, topics, layout (editor and up)
- `ArticlePolicy` — per-article; reporters cannot publish, place or reassign
- `CommentPolicy::moderate` — editors and up

Hiding a nav link is not access control.

---

## Verifying a change

`php artisan test` runs and passes — 218 tests, ~48s. Behaviour coverage exists
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

Still uncovered: the live blog append path, the layout manager's reorder, feed
*contents* as opposed to well-formedness, the e-paper reader, and OAuth sign-in.

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
- `MAIL_MAILER=log`; verification and reset links land in
  `storage/logs/laravel.log`
