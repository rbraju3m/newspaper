# Newspaper Portal — Master Plan

**Stack:** Laravel 13 · Blade (SSR) · Tailwind CSS 4 · Alpine.js · MySQL 8 · Apache
**Content language:** Bangla-first, bilingual-ready (i18n scaffolding from day one)
**Auth scope:** Full reader accounts + staff roles

> **This document is the original plan, annotated as-built.** The competitive
> analysis and design system below still describe the shipped product. Section 5
> now records what was actually built and where it diverged.
>
> Current progress lives in [`STATUS.md`](STATUS.md); the reasoning behind
> individual choices lives in [`DECISIONS.md`](DECISIONS.md).
>
> **Planned as Laravel 12; the installer pulled 13.26.** Same APIs, but three
> Laravel 13 defaults changed the work materially — see §5 notes.

---

## 1. Competitive Analysis

Analysed: prothomalo.com, bd-pratidin.com, ittefaq.com.bd, kalerkantho.com,
dailynayadiganta.com, dainikamadershomoy.com, mzamin.com, thedailystar.net,
starnews.com.bd, dbcnews.tv

### 1.1 What every one of them has (non-negotiable baseline)

| Feature | Seen on | Our decision |
|---|---|---|
| Top utility bar: date in **Bangla numerals + Bengali calendar** (`২৫ আগস্ট ২০২৬, মঙ্গলবার, ১০ ভাদ্র ১৪৩৩`) | mzamin, nayadiganta, ittefaq, dbc | Build a `BanglaDate` helper |
| E-paper / আজকের পত্রিকা | all 9 print titles | Full e-paper module |
| Archive by date (calendar picker) | mzamin, ittefaq, nayadiganta, amadershomoy | Date-archive route + calendar widget |
| Sticky category nav + "আরও" overflow + hamburger mega-menu | all | Yes |
| Search | all | Full-text search w/ filters |
| Social icons in header (FB, YT, X, Instagram, TikTok, Telegram, WhatsApp) | nayadiganta, amadershomoy, dbc | Configurable in settings |
| Most-read ranking widget | mzamin, kalerkantho, prothomalo | Yes, tabbed with Latest |
| Video section w/ YouTube embeds | all | Video module |
| Photo gallery | ittefaq, nayadiganta | Gallery module |
| Mobile app download badges | ittefaq, dbc | Footer slot (PWA instead of native at first) |
| Timestamps ("৩৮ মিনিট আগে") | prothomalo, mzamin | Relative Bangla timestamps |
| Category "আরও দেখুন" link per block | dbc, all | Standard block header component |

### 1.2 Differentiators worth stealing

- **Prothom Alo** — cleanest typography, generous whitespace, card-based grid, EN/BN edition toggle, login in header. *The design north star.*
- **The Daily Star** — Drupal 11, mega-menu, `Ds+` premium tier, Slow Reads long-form section, comment policy, structured data + AMP. *The information-architecture north star.*
- **mzamin** — print-edition vs online split (`প্রিন্ট সংস্করণ` / `অনলাইন`), most-read ranking, archive calendar, event topic clusters (`বিশ্বকাপ ২০২৬`). *Topic-cluster idea is valuable.*
- **Ittefaq / Naya Diganta** — trending topic chips under the nav (Elections, Israel-Palestine, Weather), very deep category tree (24+ categories incl. ক্যাম্পাস, প্রবাস, ধর্ম, সাহিত্য). *Adopt trending chips + deep taxonomy.*
- **DBC News** — Live TV as a first-class header item, location indicator. *Live TV / live-blog module.*
- **Naya Diganta** — sister-publication link, Bengali calendar, topic clusters for running conflicts.

### 1.3 What they all do badly — our opportunity

1. **Ad clutter & CLS** — heavy interstitials, layout jumps. → Reserved ad slots with fixed aspect boxes, lazy-loaded.
2. **No dark mode** anywhere in the set. → Ship dark mode.
3. **No reader accounts of substance** — no bookmarks, no reading history, no personalised feed. → Core differentiator.
4. **Weak mobile UX** — desktop grids crammed onto phones. → Mobile-first, real breakpoints.
5. **No font-size / reading controls** despite dense Bangla type. → A11y reading toolbar.
6. **Slow** — unoptimised images, no responsive `srcset`. → WebP/AVIF pipeline, `srcset`, CDN-ready.
7. **Poor a11y** — low contrast, no focus states, no ARIA. → WCAG 2.1 AA target.

---

## 2. Design System

### 2.1 Typography (Bangla-first)
- **Headlines:** `Noto Serif Bengali` (700/600) — authority, print feel
- **Body:** `Noto Sans Bengali` / `Hind Siliguri` (400/500) — screen legibility
- **English/Latin + numerals:** `Inter`
- **Scale:** 12 / 14 / 16 / 18 / 21 / 26 / 32 / 40 / 52 px, line-height 1.7 for Bangla body (Bangla needs more leading than Latin)
- Reader-controlled font size: A− / A / A+ (persisted in localStorage)

### 2.2 Colour tokens
```
--brand        #C8102E   (news red — masthead, category rules, live dot)
--brand-dark   #9B0C23
--accent       #1A5FB4   (links, secondary actions)
--ink          #14171A   headline text
--body         #3A4046   body text
--muted        #6B7280   meta / timestamps
--line         #E5E7EB   hairlines
--surface      #FFFFFF
--canvas       #F7F8FA   page background
```
Dark mode swaps `--canvas #0E1113`, `--surface #171A1D`, `--ink #F2F4F6`, brand lightened to `#FF4D68` for contrast.
Each category also carries its own accent colour (Sports green, Business blue, Entertainment magenta…) used on section rules and chips.

### 2.3 Layout grid
- Container `max-w-[1280px]`, 12 columns, 24px gutters
- Desktop: 8-col main + 4-col sticky sidebar
- Tablet: 12-col stacked, sidebar drops below
- Mobile: single column, horizontal-scroll rails for sub-sections

### 2.4 Core components
`ArticleCard` (5 variants: hero, feature, standard, list-row, compact) · `SectionHeader` (title + coloured rule + "আরও দেখুন") · `Ticker` · `MostReadTabs` · `AuthorByline` · `ShareBar` · `AdSlot` · `Breadcrumb` · `Pagination`/infinite-scroll · `Skeleton` loaders

---

## 3. Information Architecture

```
/                             homepage
/{category}                   category landing (nested: /খেলা/ক্রিকেট)
/{category}/{id}/{slug}       article detail
/topic/{slug}                 topic cluster (বিশ্বকাপ ২০২৬, নির্বাচন)
/tag/{slug}                   tag listing
/latest                       সর্বশেষ — live-updating
/popular                      most read
/video  /video/{id}           video hub + player
/photo  /photo/{id}           photo galleries
/opinion                      opinion + columnist index
/author/{slug}                author profile + their articles
/archive?date=YYYY-MM-DD      date archive w/ calendar
/epaper  /epaper/{date}       e-paper reader
/live                         live TV / live blog
/search?q=                    search results
/page/{slug}                  static pages (about, contact, privacy, terms, ad rates)
/offline                      PWA fallback (added in Phase 5)

Auth:    /login /register /logout /forgot-password /reset-password
         /verify-email /auth/{provider}/redirect|callback
Reader:  /account  /account/bookmarks  /account/history
         /account/preferences  /account/comments
Feeds:   /rss  /sitemap.xml  /news-sitemap.xml
API:     /api/breaking  /api/articles/{id}/live  /api/articles/{id}/share
Admin:   /admin/...
```

**As built.** Category slugs are ASCII (`/khela/cricket`); article and tag slugs
keep Bangla (`/tag/নির্বাচন`). `/account/comments` was planned but is not built.
Route registration order is load-bearing — see `CLAUDE.md`.

---

## 4. Data Model

| Table | Purpose / key columns |
|---|---|
| `users` | name, email, phone, password, avatar, role(admin/editor/reporter/reader), status, email_verified_at, bio, slug, social links, preferences JSON |
| `social_accounts` | provider, provider_id, user_id |
| `categories` | parent_id, name_bn, name_en, slug, colour, icon, position, is_active, show_in_nav, layout_type, seo |
| `articles` | category_id, author_id, editor_id, title, slug, excerpt, body, type(news/video/photo/opinion/live), status, is_breaking, is_lead, is_featured, is_premium, image, image_caption, video_url, published_at, views, shares, reading_time, locale, seo fields |
| `article_category` | multi-section placement |
| `tags` / `article_tag` | free tagging |
| `topics` / `article_topic` | curated running-story clusters |
| `media` | disk, path, mime, width, height, alt, caption, credit, uploader |
| `galleries` / `gallery_images` | photo galleries |
| `comments` | article_id, user_id, parent_id, body, status(pending/approved/spam), ip |
| `reactions` | user_id, article_id, type |
| `bookmarks` | user_id, article_id |
| `reading_history` | user_id, article_id, read_at, progress |
| `newsletter_subscribers` | email, name, token, verified_at, categories JSON |
| `polls` / `poll_options` / `poll_votes` | homepage poll widget |
| `ads` | position, type(image/html/adsense), asset, url, start/end, impressions, clicks |
| `epapers` / `epaper_pages` | date, pdf, page images, section |
| `home_blocks` | drag-and-drop homepage layout: type, category_id, position, limit, style |
| `settings` | key/value site config |
| `pages` | static pages (about, contact, privacy…) |
| `live_entries` | live-blog timeline, added in Phase 5 |
| `article_related` | editor-curated related stories |
| `comment_likes` | comment reactions |
| `redirects` | old-CMS URL preservation — table exists, **no middleware consumes it yet** |

**Not built:** `menus` / `menu_items` (navigation is driven by
`categories.show_in_nav` instead, which turned out to be enough) and
`activity_log`.

**Added during the build:** `categories.path` — a materialised path, so a
nested category resolves in one query. `articles` also carries `kicker`,
`subtitle`, `dateline`, `source`, `is_pinned`, `breaking_until`,
`translation_of` and `allow_comments`, none of which were in the original
sketch.

38 tables in total, including Laravel's own `cache`, `sessions`, `jobs` and
`migrations`.

---

## 5. Implementation Phases — as built

Phases 0–5 are complete and verified against a live database. Phases 6 and 7
are all but finished — what remains in each needs something this repository
cannot supply, and is named below. Deviations from the original plan are called
out inline.

### Phase 0 — Foundation ✅
Laravel 13 · MySQL · Vite + Tailwind 4 + Alpine · self-hosted Noto Serif/Sans
Bengali + Inter · semantic light/dark design tokens · `App\Support\Bangla`
(digits, relative time, day-part clock, Bengali calendar) · base layout, header,
mega-menu, footer.

*Deviation:* the Bengali calendar turned out to warrant real test coverage —
all twelve month boundaries are asserted against known anchors.

### Phase 1 — Data layer ✅
15 migrations / 38 tables · 23 models with scopes, casts and counter hooks ·
factories · seeders producing 55 categories, 374 articles, 107 comments.

*Deviation:* demo content is generated by a purpose-built `BanglaContent`
class rather than Faker. Latin lorem makes it impossible to judge Bangla
typography, which is the whole point of seeding a front page.

### Phase 2 — Public site ✅
Editor-configured homepage blocks · nested category pages · article detail with
JSON-LD, share bar and reading controls · full-text search with filters · date
archive · video and photo hubs · topic clusters · author pages · e-paper reader ·
static pages · RSS, sitemap, Google News sitemap.

*Deviation:* article URLs canonicalise via 301 on stale slug, missing slug or
wrong section — not in the original plan, but necessary once slugs became
editable.

### Phase 3 — Auth & reader features ✅
Register · email verification · password reset · Google/Facebook OAuth ·
login by email **or** phone (Bangla or ASCII digits) · profile with avatar ·
bookmarks · reading history with resume position · threaded comments with
moderation, likes and reporting · newsletter preferences · account deletion.

*Deviation:* Socialite requires Guzzle ≤7, so Guzzle is pinned to 7.15.5 —
a version Laravel 13 explicitly supports.

### Phase 4 — Admin CMS ✅
Dashboard (desk to-do list, publishing trend, top stories) · article editor with
live slug and SEO preview · media library with a WebP derivative ladder ·
comment moderation (single + bulk) · category tree · tags with merge · topic
clusters · drag-and-drop front-page layout · users · ads · static pages ·
settings.

*Deviation, since closed:* e-paper upload and photo-gallery CRUD were in scope
and shipped late. Both now have full admin screens — drag ordering, multi-file
upload, covers, deletion that takes its files with it — and both have demo
seeders behind them.

### Phase 5 — Interactivity & polish ✅
PWA (manifest, service worker, offline page, install prompt, update banner) ·
live blog with polling timeline and key-points rail · toast notifications ·
skeleton loaders · sticky share rail · keyboard shortcuts · back-to-top ·
offline banner.

*Deviation, since closed:* push notifications were in scope and only the
service-worker handlers existed. The rest is built — `push_subscriptions`,
VAPID config, `PushService`, subscribe/unsubscribe endpoints, an Alpine store,
`push:keys`, `push:send`, and a send button in the article editor. Guests
subscribe, because most readers of a news site are not signed in, and sending
is always an action somebody takes rather than a model event.

### Phase 6 — SEO & performance ◐ *nothing unblocked left*
`NewsArticle` JSON-LD, OG/Twitter cards, sitemaps and canonical URLs shipped in
Phase 2. Since then:

- ~~Wire `srcset`/`sizes` through the card and hero components~~ **done**, and
  through the gallery grid and the ad slots as well. A `w768` rung was added
  when Lighthouse showed the hero taking a 960 where 768 would do.
- ~~Lighthouse ≥ 90 on homepage and article, mobile~~ **done** — 95–99
  performance, **100** accessibility, 100 best-practices, 100 SEO on both.
- ~~Core Web Vitals against the budget~~ **done** — LCP 2.0–2.1s, CLS 0.000,
  and zero layout-shift entries with every lazy ad creative forced to load.
- ~~Reduce the cold-homepage query count~~ **done** — 93 to 48, and the cached
  payload 555 KB to 41 KB by storing it packed.
- **AVIF alongside WebP** — blocked on the box, not the code: this PHP has
  neither `imageavif()` nor Imagick.
- **`hreflang`** — needs a second locale to exist. The columns are there and
  nothing else is.

### Phase 7 — Hardening & launch ◐ *one item, and it needs credentials*
~~Test suite — only Laravel's stock `ExampleTest` stubs exist, and
`php artisan test` fails because `phpunit.xml` targets SQLite `:memory:` while
`pdo_sqlite` is not installed.~~ **694 tests, 3,067 assertions**, on MySQL
against `newspaper_test`, because `Article::search()` silently degrades to
`LIKE` on any other driver and the `MATCH ... AGAINST` path would never be
exercised.

Done since: ~~HTML sanitising for editor-written bodies~~ · ~~purge demo data
and logins~~ (`demo:purge`) · ~~rate-limit review~~ (60 of 61 write routes) ·
~~counter drift~~ (`counters:recompute`, nightly) · ~~scheduled publishing~~
(`articles:publish-due`, every minute) · ~~backups~~ (`backup:run`, nightly,
verified) · ~~error tracking~~ (`ErrorAlerter` + `errors:digest`) · ~~deploy
runbook~~ (`docs/DEPLOY.md`) · ~~a health endpoint that fails when a dependency
does~~ (`/up`) · ~~off-site backups and a dead-man's-switch~~ (`backup:sync`,
`BACKUP_HEARTBEAT_URL`) · ~~real branding~~ (a demo identity that declares
itself).

**Open:** the off-site copy has never run against a real bucket. Everything
about it is proven against a local directory standing in for the remote; what
is unproven is the half only a real endpoint has — credentials, region and
endpoint resolution, path-style addressing, and the multipart upload a 78 MB
archive will take, which is the case where the ETag stops being an MD5.
`STATUS.md` has the three commands.

---

### Laravel 13 defaults that changed the work

Three framework defaults materially affected the build and are worth knowing
before touching this code:

1. **`cache.serializable_classes => false`** — no PHP classes may be
   unserialized from cache at all. Every cached Eloquent payload needs an
   explicit allow-list entry.
2. **`Model::shouldBeStrict()`** — mass-assigning guarded attributes throws
   outside production, and so does lazy loading, but **only on models that
   came back from a multi-row query**. `Builder::hydrate()` sets the enforcing
   flag under `if (count($items) > 1)`, so `first()`, `find()`, route-model
   binding and `Auth::user()` all lazy-load in silence — most of the models a
   controller ever holds. `AppServiceProvider::closeTheLazyLoadingHole()`
   closes it with a wildcard `eloquent.retrieved` listener; models restored
   from the cache stay outside it, because `unserialize()` fires no event.
3. **PHP attributes for `#[Scope]`, `#[Fillable]`, `#[Hidden]`** — the newer
   model conventions are used throughout.

## 6. Success Criteria

| Criterion | Status |
|---|---|
| Reader can register, verify, log in, bookmark, comment, see history — end to end | **Met** — verified end to end |
| Editor can publish a story from admin to homepage in under 2 minutes | **Met** — one request, front page updated |
| Lighthouse ≥ 90 on home + article, mobile | **Met** — 98 home / 99 article with ads live, mobile profile, median of repeated runs |
| LCP < 2.5s, CLS < 0.1 with ads rendered | **Met** — LCP 1.8–2.2s. With every slot's creative loaded (verified by scrolling the page until the lazy images arrive, not by Lighthouse alone) both pages record zero layout-shift entries; harness validated against a control that registers 0.2673 |
| WCAG 2.1 AA | **Partial** — Lighthouse a11y **100** on home and article; contrast, tap-target and label-in-name all clear. Automated auditing only covers part of AA: keyboard traps, focus order, screen-reader flow and the admin screens are still unaudited |
