# Design & Engineering Decisions

Why things are built the way they are. Most of these look arbitrary until you
hit the thing they prevent — several were written *after* hitting it.

---

## Language & typography

### Bangla slugs, not transliteration

`Str::slug()` transliterates Bangla into ASCII: `রপ্তানি আয় বাড়াতে` becomes
`rptani-az-badate`. Lossy, unreadable, and worthless for Bangla search queries.
Article and tag slugs keep the native script; only characters that actually
break a URL path are stripped.

### `\p{M}` is not optional

The slug regex is `/[^\p{L}\p{M}\p{N}\s-]+/u`. Bangla vowel signs and hasant are
**combining marks** (`\p{M}`), not letters (`\p{L}`). Without `\p{M}`:

- `ক্রিকেট` → `করকট`
- `কৃষি` and `কাষ` both → `কষ` (a genuine collision)

This shipped broken once and was only caught because a tag URL looked odd in a
test listing.

### Bengali calendar

`App\Support\Bangla` implements the 1987-revised / 2019-amended Bangladeshi
calendar: Boishakh 1 = 14 April, months 1–5 have 31 days, 6–11 have 30, and
Falgun gains a day when the *following* February has 29. All twelve month
boundaries are asserted against known anchors.

### Line height

Body copy is `1.7`, not the `1.5` a Latin-first design would use. Bangla has
taller glyph clusters and needs the extra leading.

---

## Data model

### Materialised category paths

`categories.path` stores `khela/cricket`. A nested category resolves in one
query rather than walking parent pointers on every request, and "everything
under খেলা" is a single `LIKE 'khela/%'`. Parent renames cascade to children on
save. The cost is that a category cannot be its own ancestor — explicitly
checked, or the path builder recurses forever.

### Article URLs carry the ID

`/{category-path}/{id}/{slug}`. The ID makes the URL stable when a headline is
edited. A stale slug, a missing slug, or the wrong section all **301** to the
canonical URL, so search engines only ever index one address.

### Indexes are per query path, not speculative

`articles` carries eight composite indexes, each matching a real query: category
landing, latest feed, hero, featured, breaking ticker, most-read, video hub,
author page.

### Full-text uses the default parser

Bangla is space-delimited, so MySQL's default parser tokenises it correctly.
The `ngram` parser exists for scripts *without* word spaces (CJK) and would only
add noise here. Queries under `innodb_ft_min_token_size` fall back to `LIKE`.

### Denormalised counters, maintained in hooks

`comments_count`, `articles_count`, `replies_count` are kept correct by model
events rather than a scheduled job. Two traps, both hit in practice:

- `getOriginal()` **applies casts** — it returns a `CommentStatus` enum, so
  comparing it to `->value` is always false. Use `getRawOriginal()`.
- Bulk-updating via the query builder skips model events entirely. The admin's
  bulk moderation saves one row at a time for exactly this reason.

### Pivot timestamps are database-stamped

`bookmarks`, `reactions` and `comment_likes` use `->useCurrent()`.
`attach()`/`toggle()` only write pivot timestamps when the relation declares
`withTimestamps()`, which also insists on an `updated_at` column these tables
have no use for. Without this, "my saved stories, newest first" sorts on NULLs.

---

## Caching

### `serializable_classes` is an allow-list, not `false`

Laravel 13 ships `cache.serializable_classes => false`: **no** PHP classes may be
unserialized from cache, to blunt gadget-chain attacks if `APP_KEY` leaks. The
homepage, taxonomy and ad caches hold Eloquent collections, so `config/cache.php`
names exactly the classes involved.

A class missing from that list fails loudly with a `TypeError` rather than
silently returning a broken object — so drift surfaces immediately. **If you add
a model to a cached payload, add it to that list.**

### What is cached, and for how long

| Key | TTL | Busted by |
|---|---|---|
| `homepage.blocks` | 120s | publishing, layout edits |
| `layout.categories` | 1h | category edits |
| `layout.trending` | 10m | topic edits |
| `layout.breaking` | 60s | — |
| `ads.live` | 5m | ad edits |
| `settings.all` | forever | any settings write |

`Setting` also memoises per-request: it is read many times per render, and each
read is a cache round trip on the database driver.

### Ranked lists do not dedupe

The homepage tracks which articles it has already placed so a section block does
not repeat the hero. **Most-read and Latest are excluded** — they promise a true
ranking, and silently omitting a story that appeared higher up makes the list
wrong rather than tidy.

---

## Security

### OAuth linking requires a verified provider email

When a social login's email matches an existing local account, auto-linking is an
account-takeover path: anyone who can set an unverified address at the provider
could seize the matching account. Linking only happens when the provider asserts
`email_verified` / `verified_email`. Otherwise the login is **refused** and the
reader is told to sign in with their password.

### Guarded attributes stay guarded

`email_verified_at`, `moderated_by`, `moderated_at` and the `*_count` columns are
deliberately **not** fillable. App-set values go through `forceFill()` or a
query-builder update. `Model::shouldBeStrict()` is on outside production, so
mass-assigning them throws rather than silently dropping the value.

This class of bug appeared five times and was cleared by auditing every model's
non-fillable columns against every `create`/`update`/`fill` call site — not by
waiting to trip over each one.

### Editorial privilege is enforced server-side

A reporter posting `status=published`, `is_lead=1` and someone else's
`author_id` gets: status forced to `review`, every placement flag stripped, and
the byline forced to themselves. Editors may reassign bylines; reporters may not
— otherwise they could file copy under the editor-in-chief's name.

### Readers get 404 from `/admin`, not 403

The existence of the admin panel is not something a logged-in reader needs
confirmed.

### Every admin action authorises

Hiding a sidebar link is presentation, not access control. `manage-site` and
`manage-taxonomy` gates are checked in **every** public controller method, not
just `index`. Editors reaching `/admin/ads` directly was a real gap, found by
testing the URL rather than the UI.

### Other

- Password reset never reveals whether an address exists — otherwise the form is
  an account-enumeration oracle.
- Session ID rotates on login (fixation).
- Suspended accounts are rejected *after* a valid password check.
- Login throttling is keyed on identifier **+ IP**, so one attacker cannot lock
  out a real reader.
- Comment `parent_id` is validated to belong to the same article, or a crafted
  ID grafts a thread onto an unrelated story.
- Unverified readers cannot comment — the cheapest effective spam control there
  is.
- Comments auto-hide at 5 reports, so brigaded content does not sit live in the
  queue.
- Editing an approved comment re-enters moderation; otherwise it could be
  rewritten into anything after approval.

---

## Front end

### Alpine, not a framework

Every interactive piece — ticker, share, infinite scroll, live blog, editor — is
a small Alpine component. Total JS is ~23KB gzipped. A news reader on a 3G
connection should not download React to read a headline.

### Plugin registration order

`resources/js/bootstrap.js` exists because ES imports are hoisted: stores calling
`Alpine.$persist()` at definition time run *before* `Alpine.plugin(persist)` if
both live in `app.js`. Every store imports `bootstrap` first.

### Zero-CLS ad slots

Every ad box renders at its full reserved aspect ratio whether filled or not.
Layout shift from late-arriving creative is the single worst flaw across all ten
reference sites.

### Infinite scroll degrades

The "আরও খবর" control is a real crawlable `<a href>` upgraded by Alpine. The
AJAX path returns **the same partial** the first page rendered, so the markup
cannot drift between them.

### Service worker scope is derived from the worker's own URL

`asset()` follows the actual request root; `APP_URL` may not agree with it.
Deriving scope separately meant the worker claimed `/newspaper/public/` while
pages served from `/` — and a scope the worker cannot claim silently controls
**nothing**. Scope is now `dirname()` of the worker's own path.

### Live blog polls

Not SSE, not WebSockets. A live blog updates every few minutes at best, and
polling survives shared hosting, proxies and mobile networks that quietly kill
long-lived connections. It pauses on hidden tabs, backs off exponentially on
failure, and asks *"anything newer than id N?"* so a quiet minute costs one
indexed lookup and an empty array.

### The service worker never caches what it must not

Non-GET requests, `/admin` and `/account` are skipped entirely. A cached comment
submission would be actively harmful.

---

## Known trade-offs

| Decision | Cost | Why it stands |
|---|---|---|
| Guzzle pinned to 7.x | Not on the latest major | Socialite requires ≤7; Laravel 13 declares `^7.8.2 \|\| ^8.0`, so 7.15.5 is fully supported |
| Editor bodies stored as raw HTML, rendered unescaped | A sanitiser bug is an XSS bug | Sanitised on **write**, not on read: each model's `saving()` hook cleans it once per save rather than once per reader, so `{!! !!}` prints a value already known good. Cost: the stored markup is the cleaned markup, and widening `Html::ALLOWED` needs `content:sanitize` re-run |
| Sanitiser hand-written on `Dom\HTMLDocument` rather than HTMLPurifier | An allow-list we maintain ourselves | No new dependency, and PHP 8.4 ships a spec-compliant HTML5 parser — the HTML4 one HTMLPurifier-era code assumes is where mutation-XSS lives. `Unit/HtmlSanitizerTest` is the vector table that keeps it honest |
| `<iframe>` allow-listed by host, not by scheme | A new embed provider needs a code change | It is the one permitted element that executes code; a scheme check would admit any site at all |
| 5xx error pages are standalone; 4xx pages use the site layout | The 500 page cannot look like the rest of the site | `layouts.site` builds its chrome from composers that query the category tree, and the cache store is the database. At 500 none of that can be relied on, and a layout that throws while rendering an error page loses the error page |
| Error-page CSS is inline rather than `@vite` | The tokens are duplicated in one file | `artisan down` pre-renders 503 to a static file served without booting the app; a rebuild between `down` and `up` would point it at a bundle that no longer exists |
| Authenticated rate limits keyed by user id, not IP | A shared account is a shared bucket | A newsroom sits behind one NAT: an IP bucket would put the whole desk in one editor's allowance, and the first person to work quickly would lock the rest out |
| No global limiter on the `web` group | Nothing caps total volume in PHP | Loose enough for a shared office connection is too loose to matter. Volumetric limiting belongs at the reverse proxy or CDN |
| Tight limits live in controllers, coarse ones in middleware | Two places to look | A limit a person can hit needs to explain itself in Bangla, which middleware cannot do. `CommentController` refuses a second comment in a minute; `throttle:comment-writes` only stops what should never have arrived |
| Counters maintained by model events *and* reconciled nightly | Two mechanisms for one number | Events keep it right as it goes and are the house pattern (`Comment::booted()`); the reconcile covers what events cannot see — bulk updates, imports, a bug in the hooks. It reports drift before fixing, so the belt tells you when the braces failed |
| `ContentSeeder` calls `counters:recompute` rather than keeping its own UPDATEs | A seeder now depends on a console command | Two definitions of a correct count is how the thing that fixes drift comes to disagree about what drift is |
| `articles:publish-due` does exactly what the admin's status flip does | Denormalised counters stay stale, as they already were | Two publish paths that behave differently is how a newsroom learns not to trust one of them. Anything the cron does beyond the button is a behaviour editors cannot reproduce by hand |
| Scheduled publishing runs every minute rather than on a queue | 1,440 runs a day for a query that almost always returns nothing | One indexed lookup, and a newsroom that schedules to the minute expects the minute. A queued job per article would need a worker this deployment does not have |
| `published_at` is left as the editor set it | The stored time is not the moment of publication | It is the time that was promised, and it is what every byline shows. Overwriting it would replace "6 PM, as planned" with whenever cron got round to it |
| `APP_TIMEZONE=Asia/Dhaka` with `DB_TIMEZONE` pinned to `+06:00` | Timestamps written before the change keep their wall clock and change meaning | Not a display preference: MySQL ran on `+06` while PHP ran on UTC, so `useCurrent()` columns were six hours from PHP-stamped ones, and the admin's wall-clock scheduling inputs were six hours out. Pinning the DB zone stops the behaviour varying with the deployment box |
| Existing timestamps not shifted by a data migration | Historical ISO strings in feeds move six hours | The displayed time and every relative time are unchanged, because both are computed from the same unshifted wall clock. Provenance differs per column — an editor-entered time meant Dhaka all along, a PHP-stamped one meant UTC — so no single shift is right for all 58 of them |
| Error alerting hand-rolled rather than Sentry | No aggregation, no release tracking, no breadcrumbs | Sentry needs an account and a DSN before it does anything, and the gap being closed was "nobody is told". The reportable hook is one line — adding Sentry behind it later changes nothing else |
| Alert throttle state in the *file* cache, not the default store | One more store in play | The default store is the database, and a database outage is the failure you most need alerting on. Asking the database for permission to report that the database is down is not a question that gets answered |
| Fingerprint is class + file + line, not the message | Two genuinely different faults on one line look like one | Messages carry ids and values, so fingerprinting them makes every occurrence look new and defeats the throttle entirely |
| Alerts sent synchronously from the exception handler | A dead webhook adds latency to an already-failing request | There is no queue worker on this deployment, so a dispatched alert would queue forever. Timeouts are short and the throttle means at most one send per fault per hour |
| `ErrorAlert` is a Mailable, not `Mail::raw()` | A class and a view for four lines of text | `MailFake::raw()` is an empty method: a raw send records nothing and cannot be asserted on. An alerting path nobody can test is one nobody should trust |
| Backups verified by mysqldump's completion marker, not by exit code | One more thing to keep in step if mysqldump changes its footer | A truncated dump is a *valid* gzip file of a plausible size — `gzip -t` passes it and it restores into a half-empty database. The marker is the only cheap proof the dump finished, and a failed artifact is deleted so it cannot pose as a good one |
| `backup:run` writes to local disk only | A backup on the same disk survives a bad migration, not a dead server | Off-site is a transport problem with a different answer per deployment (rsync, S3, a managed snapshot); baking one in would be guessing. `DEPLOY.md` carries the rsync line |
| Backups hand-rolled rather than spatie/laravel-backup | An allow-list of features we maintain ourselves | Same reasoning as the sanitiser: no new dependency, and what is needed here is two shell pipelines and a verification step. `BackupTest` is what keeps it honest |
| `demo:purge` keeps taxonomy, layout, settings and pages | A purged install is not a fresh install | The 55-category Bangla tree and the homepage layout are work a newsroom would otherwise redo by hand; the demo *content* is what has to go. `migrate:fresh --seed` remains the way to get back to a blank schema |
| The purge refuses to delete every user | An install with only demo accounts cannot be fully purged in one run | Ending with nobody who can sign in is unrecoverable short of tinker. Promote a real admin first, or name one with `--keep` |
| Orphaned files under `uploads/` are reported, not deleted | A purged install can still be holding demo imagery | The command knows what the demo rows referenced; sweeping the directory is a different and much less careful promise |
| One allow-list for articles, live entries and pages | A static page cannot carry markup an article may not | Three allow-lists is three things to keep in step with `.prose-editorial`, and nothing has yet wanted the difference. `SanitizeContentBodies::TARGETS` is the list of what is covered |
| Body editor is a textarea with HTML helpers, not WYSIWYG | Editors must know a little HTML | The server-side sanitising a WYSIWYG would need now exists, so this is a UI decision rather than a safety one |
| Views counted inline, not queued | A write on article reads | One `UPDATE` is cheaper than a queue round trip at this scale; a session guard stops refresh inflation |
| Placeholder app icons | Not real branding | GD-drawn so the manifest validates; swap in real artwork |
