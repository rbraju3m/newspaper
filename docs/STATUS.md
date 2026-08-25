# Project Status

**Last updated:** 25 August 2026
**Branch:** `feat/newspaper-platform` (2 commits ahead of `main`)
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
| 6 | SEO & performance — `srcset`, Lighthouse, Core Web Vitals | **Started** — imagery live, Lighthouse 99/98 mobile; AVIF and the cold homepage remain |
| 7 | Hardening & launch — tests, backups, deploy runbook | **Started** — test harness fixed, no coverage yet |

### By the numbers

| | |
|---|---|
| PHP files (app/database/routes/config) | 132 |
| Models · Enums · Policies · Services | 23 · 5 · 3 · 4 |
| Controllers | 44 |
| Blade templates | 93 |
| Routes (115 total, 52 admin) | 115 |
| Database tables | 38 |
| Seeded content | 55 categories · 374 articles · 107 comments · 36 users |
| Seeded imagery | 60 media · 285 WebP derivatives · 50 MB on disk |
| Bundle (gzipped) | 16.0 KB CSS · 23.3 KB JS |

### Verification currently passing

- PHP lint clean across all 132 files; all JS parses
- All 93 Blade templates compile; every `@include`/`<x-component>` resolves
- 20/20 authorisation unit assertions (`CommentPolicy`, roles, OAuth gate)
- All 23 models boot and every relationship instantiates
- 19/19 public routes and 12/12 admin screens return 200, zero errors logged
- Full admin authorisation matrix verified across admin/editor/reporter/reader

These are ad-hoc scripts run during development, **not** a committed test
suite. The committed suite is 13 tests: `HarnessTest` (the harness itself) and
`ResponsiveImageTest` (the srcset wiring). Broad coverage of routes, policies,
search and moderation is still Phase 7's first job.

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

## Known gaps

### Blocking a public launch

1. **Almost no test suite.** The harness is fixed — `php artisan test` runs
   green against the `newspaper_test` MySQL database — but it contains only
   `tests/Feature/HarnessTest.php`, four tests that prove the plumbing works.
   No application behaviour is covered yet; everything under "Verification
   currently passing" above is still ad-hoc scripts. Writing real coverage is
   Phase 7's first job.
2. **Article bodies are raw HTML rendered unescaped.** Safe while only staff can
   write them. Must be sanitised before authorship widens.
3. **Demo data and demo logins are in the database.** Purge before deploying.
4. **Placeholder branding** — GD-drawn app icons, no real logo artwork, imprint
   fields hold sample values.
5. **No backups, no error tracking, no deploy runbook.**

### Feature-incomplete

6. **Galleries and e-paper still have no imagery.** ~~No images exist to
   serve.~~ `MediaSeeder` now draws a section-coloured plate library, runs it
   through `ImageService`, and links all 374 articles, so the ladder renders
   and Lighthouse is unblocked. What it does *not* cover: `galleries` and
   `gallery_images` are still empty (so `/photo` renders an empty hub) and
   `epapers` has no rows — both are blocked on their missing admin CRUD, gaps
   8 and 9, not on imagery.

   Two caveats on the generated plates. They are abstract compositions, not
   photographs: fine for layout, byte-weight and CLS work, useless for judging
   crops or focal points. And their lower rungs are lean — roughly 45 KB at
   w960 against 60–90 KB for real photojournalism — so LCP measured on them
   will read a little optimistic.
7. **Push notifications are half-present.** The service worker has `push` and
   `notificationclick` handlers, but there is no subscription storage, no VAPID
   configuration and no sending side. Either finish it (`minishlink/web-push`)
   or strip the handlers — a half-built version is worse than none.
8. **E-paper has no upload UI.** Tables, models and the public reader exist;
   issues must currently be inserted by hand.
9. **Photo galleries have no admin CRUD.** Same shape as e-paper.
10. **Newsletter never sends.** Subscribers are captured and verified; there is
    no digest job or mail template.
11. **Bilingual is scaffolded, not built.** `locale` and `translation_of`
    columns exist and slugs are unique per locale, but there is no English
    edition, no language switcher and no `hreflang`.
12. **`redirects` table is unused.** No middleware consumes it yet — it exists
    for migrating a client's old CMS URLs.

### Smaller

13. Scheduled articles need a cron entry (`status=scheduled` past its
    `published_at` is counted on the dashboard but never auto-publishes).
14. Ad impressions are never incremented — only clicks are.
15. `ArticleQuery::related()` runs a correlated `EXISTS` subquery for topic
    matching; fine at this size, worth watching as content grows.
16. Cold homepage is ~1.4s / 80 queries; warm is ~340ms / 15. The cold path
    deserves attention in Phase 6.

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
4. ~~Lighthouse pass on homepage and article, mobile profile. Target ≥ 90.~~
   **Done** — see below.
5. ~~Measure Core Web Vitals against the budget in `PLAN.md`
   (LCP < 2.5s, CLS < 0.1).~~ **Done** — LCP 1.8–2.2s, CLS 0.000 on every run.
   The `width`/`height` on the article hero did what it was added for.
6. Reduce cold-homepage query count.
7. `hreflang` + canonical review once a second locale exists.

Two things the Lighthouse pass will surface that are decisions, not bugs:

- **Author avatars are third-party.** `User::avatar_url` falls back to
  `ui-avatars.com` for all 36 seeded users, so every author page and comment
  thread makes external requests. Seeding local avatars would remove them.
- **Seeded ads are inactive.** All six ship `is_active = 0`, so `Ad::live()`
  returns none and every slot renders its placeholder. The creatives now
  exist; flip the flag if the pass should measure filled slots.

Then Phase 7: test suite, sanitising, backups, error tracking, deploy runbook.

---

## Lighthouse, 25 August 2026

Mobile profile, Lighthouse 12, simulated throttling. Medians of repeated runs.

Ads are live in every slot (`is_active = 1`, creatives from `MediaSeeder`).

| | Perf | A11y | Best Practices | SEO | LCP | CLS | TBT |
|---|---|---|---|---|---|---|---|
| Homepage | **98** | 92 | 100 | 100 | 2.1 s | 0.000 | 72 ms |
| Article | **99** | 96 | 100 | 100 | 2.0 s | 0.000 | 36 ms |

Both clear the ≥ 90 target and the CWV budget. Turning the ads on cost nothing
measurable — one point on the homepage, one gained on the article, inside run
variance.

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

### What it found

Nothing performance-related in the application, and three real accessibility
defects:

1. **`text-muted` on `bg-surface-2` is 4.34:1**, against the 4.5:1 that 13px
   text requires. `#6b7280` on `#f1f3f5`. Both pages; it is a token pairing,
   so it is wherever that combination appears.
2. **Opinion-block links are 16–22px tall**, against a 24px minimum tap target.
   Homepage.
3. **The article's font-size buttons fail WCAG 2.5.3** — `aria-label="ফন্ট বড়
   করুন"` does not contain the visible label, so speech control cannot address
   the button by what it says.

Two smaller diagnostics. The homepage ships 1,329 DOM elements. And the article
hero was flagged for 26 KB of oversize, which turns out **not** to be a `sizes`
defect: Lighthouse emulates 412 CSS px at DPR 1.75, so the hero needs 721 device
px, and with rungs at 640 and 960 the browser correctly took 960 rather than
upscale a 640. The ladder's spacing is the cause, not its wiring — a `w768` rung
in `ImageService::WIDTHS` would close most of the gap. Worth doing alongside
AVIF, since both are changes to the same ladder.

`SiteSeeder` now creates the house ads active rather than inactive. They were
switched off because there was no creative to show; `MediaSeeder` fills `asset`,
and leaving them off meant a fresh seed could not reproduce the CLS-with-ads
result recorded in `PLAN.md`.

---

## Resuming work

```bash
cd /var/www/html/newspaper
git checkout feat/newspaper-platform
php artisan serve --port=8899        # or use the Apache vhost
```

Read [`CLAUDE.md`](../CLAUDE.md) first — it lists the traps that have already
cost time once.
