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

Where there is *no* local account, the reader is still signed up — refusing
would lock out anyone whose provider simply does not send the flag — but the
account is created **unstamped** and goes through the ordinary verification
mail. Stamping `email_verified_at` on an address nobody verified claims a
verification that did not happen, and lets an account sit on an address whose
real owner has not signed up yet. Commenting is what verification gates, so
nothing is lost by making them prove it.

### Account deletion is permanent, and comments survive it

The account page says saved stories and reading history cannot be recovered, so
signing back in with the same social identity resurrects nothing — it says the
account was deleted, and the address stays spoken for because the soft-deleted
row still holds the unique index.

The delete is *soft* so a reader's published comments stay attributable, and
`Comment::user()` therefore declares `withTrashed()`. Without it the relation
went null the moment anybody deleted their account and the comment thread threw,
which made the soft delete's entire purpose a comment in the code rather than a
behaviour. `Article::author()` deliberately does **not** do this: every byline
template guards `@if ($article->author)`, so a deleted staff account drops the
byline instead of taking the page down. A comment without its author is not a
comment; an article without a byline still is.

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

### An impression is what a reader saw, not what the server rendered

`ads.impressions` is written by the browser. Counting it while building the page
is one line and wrong in both directions: creatives are `loading="lazy"`, so a
slot below the fold is frequently never fetched — measuring the front page for
CLS found only one of its three slots had loaded — while every crawler that
fetches the HTML would count as a reader. An advertiser paying per impression
would be billed the second number for the first.

So the client applies the industry rule — half the creative in view for one
continuous second — and posts every slot that qualified in one beacon, which the
endpoint turns into one query. It cannot prove the browser was honest, and
nothing client-reported can; a server-side count would be just as forgeable, by
loading the page, and wrong as well.

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
| App icons are drawn, and carry no lettering | They are shapes, not a wordmark | GD does no complex shaping, so Bangla conjuncts come out unformed and vowel signs out of order. A nameplate-shaped block beats a wrong nameplate — the same call `EpaperSeeder` makes. `brand:icons` redraws the set, `any` and `maskable` as separate files because only a *circle* of 80% diameter survives a launcher mask |
| The masthead and imprint are a fictional demo identity that says so | The site cannot be mistaken for a working newspaper, which is the point | The imprint fields are a legal requirement for a real paper, `demo:purge` deliberately keeps them, and they held a plausible Bangla name and a real Dhaka media-district address. A plausible fake is worse than an obvious blank because a blank gets noticed |
| A single-rung `srcset` is emitted rather than suppressed | Contradicts the usual advice against one-candidate srcsets | That advice is about stopping a browser reaching for something larger, and `ImageService` will not upscale, so for a slot-sized creative there is nothing larger. What there is, is WebP where the fallback is JPEG: 6.5 KB against 16.2 KB on the seeded sidebar creative |
| Strict mode's lazy-load guard is closed by hand in `AppServiceProvider` | An app-level workaround for a framework behaviour | `Builder::hydrate()` sets the enforcing flag only when a query returned more than one row, so `first()`, `find()`, route-model binding and `Auth::user()` all lazy-load in silence — most of the models a controller holds. The rule `CLAUDE.md` opens with covered a fraction of what everyone assumed |
| Cached models remain unguarded even so | A page built from a cached payload cannot demonstrate a violation | `unserialize()` fires no `retrieved`, and there is no hook that does. The payloads are cached *with* their relations, so there is normally nothing to lazy-load; what it costs is that such a page cannot be used to *test* for one |
| An e-paper edition is named by `?edition=`, not by a path segment | The URL for a second edition is uglier than `/epaper/{date}/{edition}` would be | A path segment is a third catch-all-adjacent route under `/epaper/`, and `/{category}/{id}/{slug}` is already registered last for exactly that reason. The query parameter adds no route and cannot change what any existing URL matches |
| The bare date resolves to the house edition rather than to "any of them" | One edition is privileged over the others by config order | It was an unordered `firstOrFail()`, so which issue a reader got was whatever InnoDB returned — different answers to the same URL on the same data. Deterministic matters even on a paper that will only ever run one edition, and a day the house edition skipped falls back to the alphabetically first rather than to nothing |
| The house edition's URLs carry no `?edition=`, and an explicit one 301s | The canonical URL depends on the first key of `site.epaper_editions`, so reordering that config changes every canonical e-paper address | One issue, one address. Every install is single-edition until somebody creates a second issue, and none of them should grow a query string for it — nor should the same paper be indexable at two URLs. Same rule `ArticleController` applies to a stale slug |
| The back-issue rail is scoped to the edition being read | A reader on the Dhaka edition is not offered main-edition back issues | Unscoped it listed each date once per edition, with every link leading to the same issue — the original bug in a second place. The switcher is how you cross between editions; the rail is how you move within one |
| Redirects are resolved at 404 time, not by middleware | A rule can never take precedence over live content, and cannot rewrite a URL that already works | `/{category}` is constrained `.*`, so nothing reaches a routing-level 404 and a lookup would have to run before the router to see anything — one query on every request to the site, for ever, against a table that is empty on every install that never migrated a CMS. At 404 time it costs a resolving request nothing |
| A live page wins over a rule recorded for the same path | A redundant or mistaken rule is silently dead rather than loudly wrong | A rule that shadowed real content would be invisible until somebody noticed readers landing on the wrong page. `redirects:import` reports the rules this makes dead, so "silent" applies to the behaviour and not to the operator |
| The dated-permalink collision is reported rather than fixed | `/2019/05/old-story` still reaches article 5 instead of its recorded destination | `/{category}/{id}/{slug}` takes any numeric middle segment, so the most common legacy URL shape resolves to real content and never 404s. Fixing it means changing the site's own URL scheme or the article canonicalisation — a much larger change than the table it would serve. Naming the affected rules at import time is the proportionate answer |
| `redirects:import` is the only writer, and there is no admin screen | An editor cannot add one rule without the CLI | The shape of this job is a mapping file of thousands of rows exported from the CMS being replaced. A paginated admin list of 4,000 machine-generated rules is not a screen anybody uses, and one-off rules are rare enough to be tinker's job until somebody asks |
| `hits` is incremented through the query builder | The counter goes round Eloquent, which `CLAUDE.md` warns about elsewhere | Two reasons, both real here: `hits` is not fillable, and the row is fetched with a partial select so `$redirect->increment()` throws under strict mode on an attribute that was never loaded. A hit is also not an edit — `updated_at` should still mean when the rule was last changed |
| The fallback avatar is drawn locally, not seeded | Nothing is stored, so there is no avatar to edit or reap | Seeding would have fixed the demo box and left every real reader who signs up and never uploads one still fetching from `ui-avatars.com`. The *fallback* was the third-party request, so the fallback is what had to stop being one |
| It is an SVG data URI rather than a drawn PNG | ~460 bytes of inline markup per face, not cacheable across pages | GD does no complex shaping, which is why `brand:icons` and `EpaperSeeder` are wordless — a drawn avatar could not carry Bangla initials. A browser shapes SVG text, so it can. It also costs no request at all and is vector, where `ui-avatars` returned a 64×64 PNG the author page rendered at 88 |
| `avatar_url` and `avatar_photo_url` are two accessors | Two ways to ask for the same thing, and a caller can pick the wrong one | They answer different questions. `<img src>` wants something always; metadata wants a URL a crawler can fetch, and a data URI is not one. The author page was publishing its generated fallback as `Person.image`, so a third-party URL stood as a staff member's canonical photograph |
| The data URI is built with `rawurlencode` | Larger than escaping only `#` and `<` would be | It leaves only `A-Za-z0-9-_.~` and `%`, so the URI cannot carry a quote, a space or an angle bracket into any attribute it is printed in — including the single-quoted Alpine expression in `account/index.blade.php`. Correctness in every context beats the bytes |
| Avatar colours come from the site's category palette | A colour that means "sports" on a badge also appears under somebody's initials | The alternative is a second palette to keep in step with the first. Nothing reads one as the other — a category badge is a small label with text, an avatar is a circle with initials — and the hues are already part of the design rather than invented beside it |
| `--color-cat-lifestyle` is excluded from that palette | The avatar palette is nine colours where the category one is ten | White on `#DB6B00` is 3.43:1, below WCAG AA. `AvatarTest` computes the ratio for every entry rather than trusting the comment, so a colour that fails cannot be added quietly |
| A section label below WCAG AA is darkened rather than substituted | The label is not exactly the colour the editor picked | `categories.color` is an `<input type="color">`, so a table of known-bad values is one editor away from being wrong — and the two that shipped bad prove the palette was not checked when it was designed. `Contrast::readable()` computes, so a colour picked next year is handled like the two that shipped |
| The move is a uniform mix toward black, not a swap to a darker preset | 255 candidate shades are tested where a lookup would be one step | Uniform scaling keeps the ratios between the channels, so hue and HSL saturation survive exactly: `#DB6B00` becomes a darker `#DB6B00` and not some other orange. A preset would have to be chosen per colour, which is the substitution table again |
| It stops at the first shade that clears the target | The label sits a hair above AA (4.56:1, 4.52:1) rather than comfortably above it | Anything further is the editor's colour being thrown away for margin nobody asked for. Black clears AA at 21:1 and would pass every test that only asks about legibility — `ContrastTest` asserts the ratio is *under* 4.8 for that reason |
| A colour that already passes is returned verbatim, casing included | Two return shapes from one method | Sixteen of the eighteen go through untouched, which is the point of computing rather than repainting. A normalised return would show as a diff in every digest for a change that was not made |
| `Contrast::ratio()` returns 1.0 for a value it cannot parse | A malformed colour is reported as having no contrast at all rather than as an error | Every caller asks `>= target`, so failing closed means an unreadable value can never be mistaken for a legible one. `readable()` then hands the input back unchanged: this darkens colours, and one bad row must not cost every subscriber their digest |
| `AvatarTest` keeps its own luminance implementation | The formula exists twice | It is the cross-check. A palette test that computed contrast with the class under test would pass on any formula at all, and `ContrastTest` asserts against published constants for the same reason |
| A section label carries two colours and lets CSS choose | Every label ships a value the reader will not use, and the mechanism needs a stylesheet rule to work at all | The theme is a class Alpine restores from `localStorage` after the HTML is built, so the server cannot know which one to compute. Sending one value would be right on one theme and wrong on the other — and *wrong* is the common case: sixteen of the eighteen seeded category colours fail AA on the dark surface against two on the light one |
| Labels are computed against `--color-surface`, not `--color-canvas` | A card sitting on the canvas is solved for a background it is not on | In both themes the canvas is the easier background — lighter-theme canvas is darker than white, dark-theme canvas is darker than the surface — so the surface is the conservative answer in both directions, and one constant per theme beats a per-template argument nobody would keep right |
| The two surface constants are a copy of the stylesheet's values | The same number lives in a CSS file and a PHP class | Nothing can link them at build time, and the failure is silent: a retheme would leave every label computed against a background that no longer exists while still rendering in *a* colour. `SectionLabelTest` parses `app.css` and compares, which is the cheapest thing that can actually notice |
| Borders and swatches keep the editor's raw colour | Two rules for one column, and a reviewer has to know which applies | AA is about text. The category page's underline and the block headers are the design working as intended, and a sweep of every occurrence of the colour would have repainted them for nothing. `SectionLabelTest` asserts the border still carries the raw value, so the sweep fails rather than passes |
| `readable()` is memoised on a static array | Cached state in a class with no lifecycle | It is a pure function called twice per card, and a homepage renders around a hundred cards drawn from a handful of sections — the memo turns two hundred 255-step scans into a dozen. Bounded by the number of distinct colours an editor has picked |
| Bangla is unprefixed and English lives under `/en` | The two editions are not symmetrical, and a reader cannot tell from a URL that Bangla is "an" edition rather than "the" site | Every existing URL, every indexed page and every link anyone has shared is Bangla. Moving them under `/bn` to make the pair tidy is a site-wide 301 for a second edition most readers will never open |
| The edition is in the URL and nowhere else — no `Accept-Language`, no cookie, no session | A reader whose browser is set to English still lands on the Bangla site first | A page whose language depends on a header is two bodies at one URL: the CDN caches whichever it saw first, the canonical tag contradicts half the responses, and a shared link sends the recipient something different from what the sender read |
| An edition is a second **route name** (`en.category.show`), not a route parameter | Two names for one page, and `route()` cannot be called from a shared template | A parameter cannot express "absent for the default locale" against a `.*` catch-all without making every existing URL ambiguous. `Locale::route()` is the single door, and `Category::url()` already goes through it |
| A translation is a separate `articles` row, not a `title_en` column | Two rows to publish, two comment threads, two slugs | It is what lets an English story have its own publication time, byline and status — the desk publishes the translation when it is ready, not when the original was. A column set would tie them together and make an English-only story impossible |
| `translation_of` is one hop, and validated against the *other* locale | An editor cannot chain or re-point it by hand | `exists:articles,id` unscoped accepts an article in the same edition — the `poll_options` graft again — and `counterpart()` would then find nothing while the admin insisted the two were linked. A chain would make "the other edition" ambiguous |
| An article's URL comes from its own row; everything else follows the request | Two rules to hold in your head | An English story linked from a Bangla listing has to be an `/en` URL, and `ArticleController` canonicalises against that value — a request-derived URL would 301 the story between editions for ever |
| Listings are scoped in `ArticleQuery::deferred()`, not by a global scope | Anything building its own `Article::query()` has to remember | The admin lists both editions deliberately; a global scope would have every admin query silently hiding half the desk's work. The cost is real and it bit once — `related()`'s curated list leaked |
| `SetLocale` is on the whole `web` group, not only on `/en` | Every request pays a middleware that usually does nothing | `App::setLocale()` mutates the container and outlives the request. php-fpm hides it by discarding the process; Octane would not, and the test suite did not |
| The `@bn*` directive names did not change | A directive called `@bn` renders English on `/en` | They appear a few hundred times across 115 templates. Renaming them is a diff over nearly every view for no behaviour, and every touch is a chance to break a page. `Fmt` is the switch; `Bangla` is unchanged and still has its own tests |
| English time, counts and the masthead date are different answers, not translations | Three places where the two editions do not correspond | Bangla names the part of the day and has no AM/PM; লাখ and কোটি are 10⁵ and 10⁷ against K and M at 10³ and 10⁶; the Bengali calendar is a Bangladeshi reader's second calendar and nobody else's |
| `lang/bn.json` maps every key to itself | A file of 131 identity pairs, which looks like nothing | `APP_FALLBACK_LOCALE` is `en` because that is where the framework's own validation messages come from. Without the identity file a missing Bangla key falls through to `en.json` and renders English to a Bangla reader — the fallback becomes the bug |
| `/en` has no block-driven front page | The English edition's front door is a plain list | The homepage layout is editor-managed with one position per column; a second edition of it is a second thing for the desk to keep current, and an English front page nobody remembered to update is worse than an honest list of the latest stories |
| Bangla-only surfaces are hidden in English, not rendered in Bangla | An English page has fewer parts than its Bangla counterpart | Topics, tags, the e-paper and the author bio have no English text or no English route. A Bangla chip that leaves the edition, or a Bangla paragraph under an English article, reads as a mistake rather than as a byline |
| The switcher appears only when a published counterpart exists | A reader cannot tell from a story that an edition exists at all | A control that 404s for the 90% of stories nobody has translated teaches a reader in two clicks that it does not work, and a broken `<link rel="alternate">` is read as the pair being wrong rather than as one side not existing yet |
| An e-paper issue has one address per *edition of the site*, not per issue | `Epaper::url()` follows the request while `Article::url()` follows the row, which is two rules for what looks like one thing | There is one printed paper. Its pages are the same Bangla images from either side, so an issue does not belong to an edition the way a story does — what `/en/epaper` adds is chrome around the same document, which is what a section URL is too |
| The e-paper's page captions get an English column; the page images do not | An English reader browses Bangla page images with English captions | The images are photographs of a printed Bangla newspaper and no column changes that. The caption is the navigation — `Sport — Page 6` is what tells a reader which page to open, and it told an English reader nothing |
| The 4xx error family is translated; the 5xx family is not | Two error families with different rules | 4xx pages extend `layouts.site` and a reader reaches them inside whichever edition they were browsing. 5xx pages must render with no database and no composers, and `artisan down` pre-renders 503 to a static file — whatever locale rendered it is what every visitor gets, so a translated one would be worse than an honest Bangla one |
| `/en` is refused as a category slug | One slug an editor cannot use, for a reason that is not visible in the admin | It is a route prefix. A root section slugged `en` is shadowed by the group for ever and 404s while looking completely correct in the list — so it is refused where it is typed. `bn` stays legal, because Bangla is unprefixed and reserving it would be inventing a rule |
| The chrome hides links to pages an edition lacks, decided by `Route::has` | A link's presence depends on the route table rather than on the template | A hand-kept list of which pages are bilingual is a second thing to update, and the failure is silent — a link out of the edition looks like a link. `Route::has(Locale::routeName($name))` is right on the day a route is added and needs no edit |
| `users` gains `designation_en` and `bio_en` but no `name_en` | The byline reads in Bangla script on an English page | A person's name is their name in either edition. A romanisation is a spelling nobody agrees on that the newsroom then has to keep in step with the original, and getting it wrong is getting somebody's name wrong. What differs between editions is what the paper says *about* them |
| The author card is guarded on the edition's biography, not on the locale | A reporter with only a Bangla bio has no card on /en, same as before | It is the same answer for the right reason. The old `Locale::isDefault()` guard hid the card from everybody; this hides it only from readers it would have shown Bangla prose to, and shows it to everybody else |
| `CARD_RELATIONS` carries `designation` but not `designation_en` | The card select is one column short of what a `display_*` accessor can read | `/opinion` is the only listing that prints a byline's job title and it has no English edition, so the column would be dead weight on every card on every page. `BilingualTest` asserts the coupling — an `/en/opinion` route makes that test fail — rather than leaving it to whoever adds the route to remember |
| A tag's slug is the same in both editions, and stays Bangla | `/en/tag/ক্রিকেট` is an English page at a percent-encoded Bangla address | A second slug column gives a tag two identities and forces `Tag::articles()` to choose one. Percent-encoded paths have worked correctly in browsers and search engines for twenty years, and the Bangla site already ships them everywhere |
| A name falls back to Bangla; a description falls back to nothing | The two fields degrade in opposite directions, which reads inconsistent until you hit it | A heading has to render something or the page has none, and a slug-as-heading ("world-cup-2026") is worse than the Bangla name. A description is optional everywhere it appears, so nothing beats a Bangla paragraph sitting under an English heading |
| The trending rail is cached once for both editions | `name_en` is selected on every request including the Bangla ones that never print it | Same reasoning as `layout.categories`: these are the same rows either way and only the printed column differs, so a second entry could never disagree with the first. The cost is that a column missing from the select is a `MissingAttributeException` no lazy-loading guard can see — `unserialize()` fires no events |
| `articles:translate` writes generated English, not a translation | The demo box's English copy has nothing to do with its Bangla copy | A machine translation of filler is filler that reads as though somebody checked it. It is deterministic on the source id and insert-only, which is what `MediaSeeder` had to learn the hard way |
| The colour is keyed on the reader's name, not their id | Two readers with the same name share a colour, and renaming changes it | An id-keyed colour shifts whenever the table is rebuilt, which is exactly when somebody is comparing screenshots between environments. The initials change on a rename too |
