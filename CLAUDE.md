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

### The lazy-load guard is closed by hand, because Laravel leaves it open

The half of the rule above that everything relies on has a hole, and it is not
in this application's code. `Builder::hydrate()` stamps the instance flag that
enforces it:

```php
$model = $instance->newFromBuilder($item);

if (count($items) > 1) {                       // ← the hole
    $model->preventsLazyLoading = Model::preventsLazyLoading();
}
```

A query that returned **one row** hands back a model with
`preventsLazyLoading = false`, so `$one->relation` loads silently. Only models
that arrived in a multi-row result set are guarded.

`Model::preventsLazyLoading()` — the static — is still true. It is the
per-instance copy that decides, and the framework only sets it when the result
had more than one row.

So `SocialAccount::where(...)->first()` followed by `->user` is a lazy load
that no test and no local click-through will ever flag. It is not a crash
risk; it is the opposite, and that is the problem. **The safety net does not
cover single-row fetches** — including every route-model-bound model and
`Auth::user()`, both of which are single-row fetches — **so an N+1 can walk
straight through the place everybody believes it cannot.** Eager-load on a
`first()` the same way you would on a `get()`; the guard will not remind you.

`OAuthSignInTest` is where this was found, and only because a test that was
*expected* to fail did not.

**This is closed.** `AppServiceProvider::closeTheLazyLoadingHole()` sets the
flag from a wildcard `eloquent.retrieved` listener, which fires from
`newFromBuilder()` for every hydrated row — before the `count()` check above,
so the framework simply overwrites it with the same value when it does agree.
Outside production only, guarded on `Model::preventsLazyLoading()`, so
production registers no listener and pays nothing. `HarnessTest` pins both
halves: that a `first()` result is guarded, and that a *freshly created* model
is still exempt — the framework skips the violation when `wasRecentlyCreated`,
and without that exemption every factory in the suite would throw.

Three things follow.

**A lazy load is now a 500 in development where it used to be a silent extra
query.** That is the point, and it is the same trade the rest of strict mode
makes. Eager-load on a `first()` the way you would on a `get()`.

**Anything restored from the cache is still unguarded** — `PackedCache` and
plain `Cache::remember()` alike. `unserialize()` fires no `retrieved` event,
so a cached Eloquent model carries the flag off and a lazy load off it throws
nothing at all.

That is broader than it first looks, and it is worth knowing before writing a
test: a page whose models come from a cached payload — the front page, and
every ad slot on the site — **cannot** demonstrate a lazy-loading violation.
A test that renders such a page and asserts 200 will pass with the eager load
removed *and* the accessor's guard removed. `AdCreativeSizingTest` asserts
against a freshly queried model instead, and says why.

These payloads are cached *with* their relations loaded, which is the point of
caching them, so there is normally nothing to lazy-load — but a relation left
out of one loads in silence. `HomepageCacheTest` covers that for the front
page by asserting the card relations are present; `AdCreativeSizingTest` does
the same for `ads.live`.

**It costs one property assignment per hydrated row.** 23 on a cold homepage,
46 on a category page, and **0 on a warm homepage** — the front page comes back
from `PackedCache`, which never hydrates through the builder at all.

**Repeating the sweep.** The listener makes a violation loud on its own now, so
this is only needed to double-check the listener itself. Close the hole in
vendor, run everything, put it back:

```bash
F=vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php
cp $F /tmp/Builder.bak
sed -i 's/if (count($items) > 1) {/if (count($items) >= 1) {/' $F
php artisan test            # violations surface as failures AND errors — read both
cp /tmp/Builder.bak $F
```

Read `errors` as well as `failures` in the JSON: a violation thrown outside a
request arrives as an error and is easy to miss.

The suite passes, and so does a crawl of every parameterless GET route plus a
bound example of each parameterised one. The three sites the first sweep found
(`Site\CategoryController` → `children`, `Site\PollController::results()` →
`options`, `Admin\LiveEntryController` → `article`) are fixed, and two test
files that did the same thing were fixed with them.

Worth knowing before treating a violation here as a performance bug: none of
those three cost a query. A lazy load of a single relation is one query and an eager
load of it is also one query — `where parent_id = ?` becomes
`where parent_id in (1)` and nothing else changes. What it buys is that the
rule holds everywhere, so the day one of those reads moves inside a loop the
eager load is already there.

### Cached models need allow-listing

`config/cache.php` → `serializable_classes` is an explicit list. Adding a model
to a cached payload without adding it there produces a `TypeError` on the *next*
request, not the one that wrote it.

That list still applies to the two payloads stored packed — see below. The
unserialize simply happens in `PackedCache` rather than in Laravel's store, and
it reads the same config key, so there is one list and not two.

### The two big cache payloads are stored packed, not plain

`homepage.blocks` and `layout.categories` go through `App\Support\PackedCache`
instead of `Cache::remember()`. They are whole Eloquent graphs — 555 KB and
106 KB serialized — and `CACHE_STORE` is the database, so plain they were
660 KB pulled out of MySQL on **every** request to the site. Packed they are
41 KB and 6 KB, and they are *faster* to read back (6.4ms against 7.5ms):
inflating 41 KB and parsing it beats parsing 555 KB of serialize text.

Three things follow.

**The stored form is base64, not raw zlib.** `cache.value` is a `mediumtext` in
`utf8mb4` and compressed output is binary, which MySQL rejects outright. Base64
gives back a third of the saving and is still thirteen times smaller than plain.

**Rolling back past this needs `php artisan cache:clear`.** Old code reading a
packed entry gets a base64 string where it expects an array, and the front page
is a 500 until the entry expires. The forward direction is safe by
construction — `PackedCache` treats anything that is not a packed string as a
miss and rebuilds — and so is a corrupt or half-written row. Only the rollback
needs the sweep, and `DEPLOY.md` says so.

**It only pays on large graphs.** Below a few KB, compressing and base64-ing a
payload makes it bigger. `layout.trending` is 112 bytes and is deliberately
left on plain `Cache::remember()`.

### `LayoutComposer` composes four views, and used to query four times

`AppServiceProvider` binds it to `partials.header`, `partials.mega-menu`,
`partials.search-overlay` and `partials.footer`. Laravel resolves a composer
from the container **per view**, so four instances each re-read the same three
cache keys: twelve round trips to the `cache` table on every request on the
site, warm or cold, for three distinct values.

It is bound `scoped` and memoises the three in instance properties. The scoped
binding is what makes that correct: a `static` would carry one test's category
tree into the next, which the cache store cannot do because `RefreshDatabase`
truncates it.

**Anything that drops those keys must drop the memo with them.**
`LayoutComposer::flush()` is the one door — it forgets all three keys *and* the
scoped instance. Forgetting the keys by hand leaves an editor who just renamed
a category looking at the old name for the rest of the request that renamed it.

### A soft-deleted user is still somebody's byline

`Comment::user()` declares `withTrashed()`, and it has to. Account deletion is
a **soft** delete precisely so a reader's published comments stay attributable
— but the relation obeys the SoftDeletes scope like any other, so without it
`$comment->user` goes null the moment somebody deletes their account and
`comment/item.blade.php` reads `$comment->user->avatar_url` with no guard.
Every article page carrying one of their approved comments then throws
`Attempt to read property "avatar_url" on null`.

`Article::author()` is deliberately *not* the same: every template that prints
a byline guards it with `@if ($article->author)`, so a deleted staff account
drops the byline instead of taking the page with it. Two different answers to
the same question, and both are on purpose — a comment without its author is
not a comment, an article without a byline still is.

Anything else that soft-deletes and is read back through a relation needs one
of those two, chosen deliberately.

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

### `MediaSeeder` heals broken imagery and nothing else

Two things write `articles.image_id` across the table: `photos:import --assign`
spreads a folder of real photographs, and `MediaSeeder` spreads its drawn
section plates. Both are deterministic and both use a query-builder `update()`.

The seeder used to relink *every* article on every run, which is
indistinguishable from healing right up until the imagery is somebody's
deliberate choice — so re-seeding to repair one broken ad replaced every
photograph on the site. It now assigns only to articles whose lead image is
genuinely absent: a null `image_id`, a media row that has since been deleted,
or a path with no file behind it.

Two consequences worth knowing:

- **The plate library is drawn lazily.** If nothing needs a plate, none are
  drawn — 54 of them cost about a minute, and on a box whose articles all carry
  photographs every one would be an orphan. A seeder run that only repairs the
  ad slots takes about a second.
- **Hand it a directory of photographs and it will not use them.** It draws its
  own plates from each section's colour; `photos:import` is the only thing that
  reads a folder.

`MediaSeederTest` pins the refusal. Anything that changes what counts as
"missing" belongs there, because the failure mode is silent: the wrong answer
looks exactly like a working re-seed.

### The live-blog cursor may only advance as far as what was sent

`ApiController::liveEntries()` answers two different questions and they want
opposite ends of the timeline.

Without a cursor it is a first load: the **top** of the timeline, pinned above
newest, capped at 30. Nothing below the fold matters, because the client is
seeded with `$entries->max('id')` from the server-rendered timeline — so it
only ever asks without a cursor when the page rendered nothing.

With a cursor it is an incremental poll, and it wants the **oldest** entries
above that cursor, not the newest. `latest` returned as `max(id)` over the
whole timeline while `entries` was capped stepped the client straight over any
burst bigger than one page, and since it only ever asks for ids above its
cursor, those updates were gone for good.

Both branches return newest-first, because `live-blog.js` prepends the array as
it arrives — the response order *is* the display order. Change the ordering on
either branch and check that file first.

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

### Renumbering under a unique constraint needs two passes

`epaper_pages` has `unique(epaper_id, page_number)`. Writing a new order
straight out fails the moment two pages swap: setting page 2 to 1 while a page
1 still holds it is a duplicate key, not a reordering. It is not a rare case —
it is what every drag does.

`EpaperController::renumber()` parks every page in a 100+ scratch band, then
writes the final numbers, both inside one transaction so a failure between the
passes cannot leave the issue parked. The band is free by construction:
`MAX_PAGES` is 99, so no live page number can be in it, and 100+99 still fits
the unsigned tinyint.

Deleting a page renumbers too — a hole means the next upload computes
`max + 1` and lands on a number that is already taken.

`galleries.gallery_images` has no such constraint, which is why the gallery
reorder can write straight through. Do not copy that one here.

### `ImageService::release()` runs *after* the owning row is gone

It is reference counted across eleven columns — `articles.image`,
`gallery_images.path`, `galleries.cover` and the rest — so calling it while the
row still holds the path means the file is a reference to itself and nothing is
ever freed.

The failure is silent in the worst way: the rows do get deleted, the redirect
is a 302, the screen shows what you expect. Only the disk disagrees, and only
if you go and count. `GalleryController::destroy()` shipped this way for an
hour and no test caught it, because the tests were asserting rows.

Collect the paths, delete the rows, *then* release. And when a gallery is
involved, move `cover` off the path first — it is one of the columns counted.

`GalleryAdminTest` asserts the files are actually missing afterwards, and that
a photograph an article still uses survives.

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

### A backup that cannot be proved must not survive

Both halves of the backup refuse to leave an artifact they could not verify —
locally `backup:run` deletes a dump with no `Dump completed` marker, and
off-site `backup:sync` deletes an object whose size does not match what was
uploaded. That is not belt-and-braces. A short object sitting in a bucket looks
exactly like a backup, and the day you find out is the day you needed it.

Three things about the off-site half:

- **It copies verified artifacts; it does not stream to the bucket.** A
  `mysqldump | gzip | aws s3 cp` pipeline cannot check its own completion
  marker, so it uploads truncated dumps with total confidence. Local first,
  verify, then up — and `backup:run` calls `backup:sync` itself so one exit
  code means "checked, and not only on this machine".
- **An unconfigured remote is a silent success.** `filled(bucket)` is the
  whole test, and an install that has not set it up must not fail its cron
  every night to say so. The cost is that a *mistyped* bucket also reads as
  "not configured" — which is why the command prints the disk it looked at.
- **`backups_offsite` sets `throw => true`** where every other disk sets
  false. Elsewhere a failed write returning `false` is a degraded page; on a
  backup destination it is a night with no off-site copy that reported
  success.

`ErrorAlerter` covers the backup that *breaks*. Nothing in here can cover the
backup that never runs — a deleted cron entry reports nothing, because nothing
runs — which is what `BACKUP_HEARTBEAT_URL` is for, and why it is pinged only
after everything has verified. A heartbeat that fires before the off-site copy
would be green on the night the bucket credentials expired.

### `/up` rethrows instead of failing when `APP_DEBUG=true`

Laravel's health route catches the `DiagnosingHealth` listener's exception and
answers 500 — *unless* debug mode is on, in which case it rethrows and you get
a stack trace. So probing `/up` on this box does not show what a monitor would
see, and a test asserting the 500 has to set `app.debug` false first.

The listener is `App\Listeners\DiagnoseHealth`, and what belongs in it is
narrow: a dependency the site genuinely cannot serve without, checked cheaply
enough to run once a minute for ever. SMTP, push and the backup bucket are
deliberately absent — all three can be down for an hour without a reader
noticing, and a check that goes red while the site is fine trains everyone to
ignore the alert.

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

### Web Push has three ways to fail silently

**The payload keys are a contract across two files.** `PushService::payloadFor()`
writes `title`, `body`, `url`, `icon`, `tag`; `public/sw.js` reads them by name.
Rename one on either side and the notification still arrives — showing the
fallback title, on every device whose worker has not updated. A worker updates
when `sw.js` changes bytes, which is not the moment the server deploys, so the
two are never in step and the old shape has to keep working.

**`new WebPush()` is a 500 on a box without GMP or BCMath.** Its constructor
calls `Utils::checkRequirement()`, which reports the missing extension with
`trigger_error(E_USER_NOTICE)` **when it is given no logger** — and Laravel's
handler turns an `E_USER_NOTICE` into an `ErrorException`. Passing
`Log::getLogger()` as the seventh constructor argument is not optional here;
without it the admin's send button is a 500 on this box and any other without
those extensions.

**A 410 is not an error.** A push service answering 404 or 410 is saying the
browser is gone — uninstalled, permission revoked, site data cleared — and
continuing to send is what gets a sender rate-limited or blocked outright.
`isSubscriptionExpired()` is the one failure `PushService` treats as routine,
and the row goes immediately. `PushResult` counts pruned separately from failed
for the same reason: they mean opposite things.

Two more worth knowing:

- **The subscription is identified by `endpoint`, not by user.** Guests
  subscribe — most readers of a news site are not signed in. `user_id` is a
  label a row acquires if somebody is signed in on that browser, and it is what
  lets the account preferences screen stand a subscription down. It cannot
  raise one: only the browser grants permission, which is why that screen
  carries a per-browser toggle *beside* the account checkbox rather than
  instead of it.
- **Rotating the VAPID pair unsubscribes the entire site**, silently. A browser
  rejects a message signed by a key it did not subscribe under, and nothing can
  tell it to re-subscribe. `push:keys` refuses to print over a configured pair
  without `--force` for that reason. Back the pair up with the database.

Sending is a command an operator runs and a button an editor presses, never a
model event. `is_breaking` is a *display* flag that drives the ticker and gets
toggled while writing; wiring a notification to every reader onto a checkbox is
how a typo becomes something you cannot take back. `articles.push_sent_at` is
the guard against a second send, and it is stamped even on a partial failure —
sending twice to everyone it reached is worse than missing the handful it did
not.

### The newsletter runs with no queue worker

Nothing in this application is queued, because nothing runs `queue:work`.
`QUEUE_CONNECTION=database`, so a `ShouldQueue` mailable would land in the
`jobs` table and sit there for ever, looking exactly like a send that worked.

Both newsletter paths therefore send inline: the double opt-in mail from the
request (throttled to five an hour, which is what makes SMTP latency in a
request acceptable), and the digest from a cron process, where blocking is the
point. `ErrorAlerter` made the same choice for the same reason. If a worker
ever exists, dispatching is the upgrade — and until then, adding `ShouldQueue`
to a mailable here silently stops it being delivered.

Two things follow for anything that sends:

**A failed send must not take the request with it.** `NewsletterController`
catches and logs. The subscriber row is already written and the reader has
already been told to check their inbox; a 500 would leave them looking at an
error while the subscription quietly exists.

**`last_sent_at` is stamped per subscriber as each one succeeds**, not in a
batch at the end. A run that dies at row 2,000 of 4,000 must leave the first
2,000 marked, or the re-run mails them twice.

### An unsubscribe link is fetched by machines

`GET /newsletter/unsubscribe/{token}` used to unsubscribe on sight. Gmail,
Outlook and every corporate mail scanner fetch the links in a message to check
them before a human sees it — so that route silently unsubscribed readers who
never clicked anything, and there is no signal anywhere when it happens.

The link now renders a confirmation and the button posts. **`List-Unsubscribe`
must name the POST route**, not the confirmation page: the mail client calls it
directly and renders nothing, so pointing it at a page asking "are you sure?"
leaves the reader still subscribed and certain they unsubscribed.

That POST is in `validateCsrfTokens(except:)` — it arrives from Gmail's own
chrome with no session and no token. The 64-character subscriber token in the
URL is the credential, and the only thing the request can do is stop that
address receiving mail.

`RFC 8058` also wants a 200 with no body for the one-click case rather than a
redirect, which is why `destroy()` returns `Response|RedirectResponse` — see
below on why that union is spelled out.

### Email HTML is not the site's HTML

`resources/views/components/mail/shell.blade.php` is tables and literal hex
colours on purpose. Gmail's mobile clients strip `<style>` from the head,
Outlook renders through Word, and none of them know what a CSS custom property
is — so not one of the semantic tokens in `app.css` survives, and `bg-surface`
in an email is a class that styles nothing.

The masthead is type on a coloured bar rather than a logo image, because remote
images are blocked by default in most clients and a masthead that renders as a
broken-image icon is worse than one made of words. Only the lead story carries
an image, and nothing depends on it loading.

Every digest needs both parts: `Content` declares `view:` **and** `text:`. A
mail with no plain-text alternative reads as spam to a filter before it reaches
anybody.

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

### The branding is a fictional demo, and says so

There is no publication behind this install. The masthead, the imprint and the
six static pages are a *coherent invented* identity, and each of them declares
itself rather than looking plausible.

That is a deliberate line, not fussiness. `editor_name`, `publisher_name` and
`office_address` are what a newspaper is legally required to publish, and
`demo:purge` **keeps** the imprint group — so whatever is seeded there is what
a launch ships unless somebody edits it. The seed used to hold a real-sounding
Bangla name and a real Dhaka media-district address, and it would have gone
out. A plausible fake is worse than an obvious blank because a blank gets
noticed.

Two rules follow for anything touching this:

- **Never invent an imprint, an address or a masthead that could belong to
  somebody.** The previous masthead, `দৈনিক সংবাদ`, is a Bangladeshi daily
  founded in 1951 — check any replacement against the real ones.
- **The six static pages are written, not generated.**
  `Database\Seeders\Support\StaticPageContent` holds them. They were
  `BanglaContent` filler, which is right for 374 articles nobody reads and
  wrong for the pages a reader opens *because* they want an answer.

`BrandingTest` pins both, and detects filler by looking for `BanglaContent`'s
stock vocabulary — with a test that the detector still recognises filler, so a
change to that vocabulary cannot quietly turn the check into one that passes
on anything.

### Icons are drawn, wordless, and `any` is not `maskable`

`php artisan brand:icons` draws the set from the brand colour. Two things it
gets right that the hand-made files did not:

**`any` and `maskable` are different files.** A maskable icon is cropped to
whatever shape the launcher likes, so only a *circle* of 80% diameter is
guaranteed — tighter than the 80% square people assume, because a square that
touches that circle has its corners outside it. The old `icon-512.png` was
declared maskable with content running to 81% of the canvas and lost its
corners on every circular launcher, silently.

**`favicon.ico` is a real multi-size icon.** It was a zero-byte file: an empty
200, which is not a 404. Browsers still request `/favicon.ico` whatever the
`<link rel="icon">` says, and cache the nothing they get.

They carry **no lettering**, and must not. GD does no complex shaping, so
Bangla conjuncts and vowel signs come out unformed and out of order — the same
reason `EpaperSeeder` draws a nameplate-shaped block rather than a nameplate.

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

**A page building several card lists at once wants `ArticleQuery::deferred()`
instead**, then one `ArticleQuery::hydrateCards()` over every article it ended
up with. `cards()` is right for one list — one query, three eager loads. The
front page is the other shape, a dozen independent lists on one response, and
there each `with()` is its own round trip: the same handful of sections,
bylines and photographs fetched a dozen times over. Eloquent sets relations on
the model instances themselves, so the lists see them without being handed
back.

Nothing may read a relation in between. Strict mode forbids lazy loading, so a
card touched before the hydrate pass is a 500 rather than a slow page — which
is why `HomepageService::articlesIn()` is a blind walk over the block data
rather than a `match` on block type. A block type it failed to list would be a
broken front page, not a slower one.

### Cache busting

After changing anything an editor controls, clear what depends on it:

```php
HomepageService::flush();   // publishing, layout blocks
LayoutComposer::flush();    // category or topic edits (header, nav, footer)
AdService::flush();         // ad edits
Setting::flush();           // settings (also clears the per-request memo)
```

`LayoutComposer::flush()` replaced the hand-written
`Cache::forget('layout.categories')` pairs. Use it rather than forgetting the
keys directly: it also drops the scoped instance holding this request's memo of
them, and forgetting one without the other is a stale header on the very
request that made the edit.

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

### An impression is not a render

`ads.impressions` is written by the browser, from `ad-impressions.js`, and not
by the code that builds the page. Counting server-side is one line and wrong
in both directions: creatives are `loading="lazy"`, so a slot below the fold is
frequently never fetched — the CLS measurement found only one of the front
page's three slots had loaded by the end of a run — while every crawler that
fetches the HTML would count as a reader.

The rule applied is the industry one: half the creative in view for one
continuous second. Everything that qualifies on a page goes up in **one
beacon**, and the endpoint turns it into **one query** —
`Ad::live()->whereIn('id', $ids)->increment('impressions')`. Three slots must
not be three writes on a front page that is eight queries warm.

Three things to keep if this is touched:

- **`live()` scopes the increment**, so a tab left open cannot keep paying a
  creative that has been paused or has run past its end date.
- **The id list is capped**, because it is client-supplied and an uncapped
  `whereIn` is a write to every row in the table.
- **`sendBeacon` cannot set headers**, so the CSRF token rides in the JSON
  body and Laravel reads `_token` out of the parsed input — the same shape
  `reading-tracker.js` uses. Change one and check the other.

It cannot prove the browser was honest, and nothing client-reported can. The
rate limiter bounds it; beyond that the number is what it is. A server-side
count would be just as forgeable and wrong as well.

**A slot only becomes a link when the ad has a `url`.** `AdController::click()`
404s without one, and every seeded house ad has none — so wrapping
unconditionally made all six links to an error page.

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
- `manage-taxonomy` — categories, tags, topics, layout, galleries, e-paper
  (editor and up)
- `ArticlePolicy` — per-article; reporters cannot publish, place or reassign
- `CommentPolicy::moderate` — editors and up

Hiding a nav link is not access control.

---

## Verifying a change

`php artisan test` runs and passes — 706 tests. The ~98s this used to quote was
measured at 568 on an idle box; `HomepageCacheTest` adds about 20s of its own,
since it builds the front page from scratch several times over. Behaviour
coverage exists for both halves of the app:

| File | Covers |
|---|---|
| `HarnessTest` | the harness itself — driver, FULLTEXT index, strict mode |
| `PublicRoutesTest` | every public URL, canonicalisation, draft visibility, that the feeds parse, output-buffer balance |
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
| `EpaperSeederTest` | `EpaperSeeder` — a drawn issue, the dates it skips, and that a redraw reproduces it |
| `EpaperAdminTest` | the e-paper admin — page numbering against its unique constraint, the PDF, and deletion |
| `GalleryAdminTest` | the photo gallery admin — uploads, ordering, the cover, deletion and its files, and the public hub |
| `MediaSeederTest` | that `MediaSeeder` heals broken imagery without replacing imagery that works |
| `GallerySeederTest` | that `GallerySeeder` curates only the imagery seeding owns, and never twice inside one gallery |
| `Unit/SeedImageryTest` | the seed arithmetic behind every drawn image, and that it raises no deprecation |
| `PushNotificationTest` | Web Push — who may subscribe, the account switch, sending, pruning a gone browser, and who may press send |
| `NewsletterTest`, `NewsletterDigestTest` | subscribe/verify/unsubscribe including one-click, and the digest — who receives it, what it holds, and that a quiet news day sends nothing |
| `PhotoImportTest` | `photos:import` — the transcode, the flattening, idempotency, and deterministic assignment |
| `LiveBlogTest` | the live blog — appending, ordering, who may run one, and the polling cursor |
| `LayoutReorderTest` | the front-page layout manager — drags within and across columns, the cache flush, and that a column change cannot collide |
| `HomepageCacheTest` | what the front page costs — that card relations load once for the page rather than once per block, that the payload is stored packed and round-trips, and that an unreadable entry rebuilds instead of 500ing |
| `BackupSyncTest` | the off-site copy — what goes up, what is skipped, what is deleted for failing verification — and the heartbeat and alerting around a failed run |
| `FeedContentsTest` | what the three feeds *say* — the RSS channel and item fields, the canonical link, the 40-item cap, that a draft, a scheduled story and a retraction stay out, the sitemap's URL set, and Google News's 48-hour window |
| `EpaperReaderTest` | the public e-paper — which issue `/epaper` opens, how a date with two editions resolves and how `?edition=` names one, the canonical URL, the edition switcher, the back-issue rail, page order and the thumbnail fallback, the shapes a half-built issue takes, and that a malformed date falls through to the catch-alls |
| `OAuthSignInTest` | Google and Facebook sign-in — the provider guards, the three cases `resolveUser()` decides between, the account-takeover refusal, what a deleted reader is told, and session fixation |
| `BrandingTest` | what a fresh install presents itself as — written static pages, an imprint that cannot be mistaken for a real one, a real favicon, and the manifest icon set |
| `AdImpressionTest` | ad impressions counted from the browser, the one-query batch, what is refused, and that an ad with no URL is not a link |
| `AdCreativeSizingTest` | ad creatives served at the slot size — the media link, the ladder, the single-rung case, and the cached payload |

Every area the coverage table once listed as missing now has a file. What
`OAuthSignInTest` still cannot reach is the half that only a real provider
has: the token exchange, the state parameter, and whether the client id and
redirect URI registered at Google actually match this deployment. It fakes the
provider, so it proves the controller's decisions and nothing about the
handshake.

**The feeds are cached, so a feed test may request each one only once.**
`feed.rss`, `feed.sitemap` and `feed.news-sitemap` are `Cache::remember()` for
ten minutes, six hours and five minutes. A second request inside one test
answers from the first one's payload, so fixtures created in between are
invisible and the assertion passes against stale content. `FeedContentsTest`
makes exactly one request per test for that reason.

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

### A before/after session id proves nothing unless the cookie is carried

The obvious way to test that sign-in rotates the session id —

```php
$this->get('/login');
$before = session()->getId();
$this->post('/login', [...]);
$this->assertNotSame($before, session()->getId());
```

— cannot fail. Laravel's test client does not carry a response's cookies into
the next call, so the second request starts a session that has nothing to do
with the first and the two ids always differ. Delete the `regenerate()` the
test is guarding and it still passes.

`LoginTest` shipped this shape and was vacuous for it.

Use `TestCase::continuingSession($response)`, which feeds the first response's
own session cookie back — it is already encrypted, so `withUnencryptedCookie()`
is the right door and `EncryptCookies` decrypts it like a browser's. Two more
things are needed and neither is optional:

- **A store that persists between requests.** `SESSION_DRIVER` is `array`, so
  a carried cookie names a session that no longer exists. `config(['session.driver' => 'file'])`
  in the test.
- **A control assertion, first.** Two plain requests on the carried cookie
  must keep the *same* id. Without it a harness that quietly stopped carrying
  anything reports a rotation on every run, which reads exactly like the thing
  working.

`LoginTest::test_a_fixated_session_does_not_survive_login` and
`OAuthSignInTest::test_a_fixated_session_does_not_survive_sign_in` both do it,
and both were checked by removing every rotation on their path — the
controller's own `regenerate()` *and* `SessionGuard::updateSession()`'s
`regenerate(true)` — at which point they fail.

That second call is worth knowing about on its own: the guard rotates the id
itself on every login, so deleting a controller's explicit `regenerate()` does
**not** reintroduce fixation and a test that appears to guard that line is
really guarding the framework. Test the property, not the line.

### Two tests cannot share `errors-<pid>.log`

`ErrorAlertTest` points `logging.channels.errors.path` at
`storage/framework/testing/errors-<pid>.log`. Anything else that triggers
`ErrorAlerter` and picks the same filename writes into the file that test is
counting lines in — same PHPUnit process, same pid, so the name collides
exactly. `BackupSyncTest` did this and turned into a failure inside
`ErrorAlertTest`, which is a long way from the file that caused it and passes
in isolation.

Give the log its own name, point the channel at it in `setUp`, and
`Log::forgetChannel('errors')` before deleting it in `tearDown` — the manager
caches resolved channels and Monolog holds the handle open.

### A cold buffer pool reads exactly like a missing index

The **first** query to touch a table after MySQL starts pays to fault its pages
in, and on this box that is hundreds of milliseconds regardless of how small
the table is. Measured here: `select * from epapers where is_published = ?
order by date desc limit 1` took **1,076ms against six rows**, and 0.6–1.5ms on
every run after. The homepage did the same thing — 2.8s and 460ms on one
`whereIn` over four categories, then 380ms total for the identical 93 queries.

So a one-off profile of a cold route is worthless, and worse than worthless if
believed: it points at whichever table happened to be touched first. `/epaper`
was flagged as a 3.5s route on exactly this evidence and turned out to be one
of the fastest on the site — 80ms — once the pages were resident.

**Profile the second run, not the first, and quote query counts rather than
seconds.** Counts are stable; wall-clock on this box swings by a factor of
three between byte-identical runs, because it is a working desktop with a load
average near 8. Before blaming a query, `EXPLAIN` it — the epaper queries were
already on `(is_published, date)` with a backward index scan and no filesort.

The other half of a slow first hit is Blade compilation, which is real but is
paid at deploy time: `php artisan optimize` runs `view:cache`. A slow first hit
after `view:clear` is a state production never sees.

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
