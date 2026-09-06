---
artifact_contract: "ce-handoff/v1"
created_at: "2026-08-30T00:00:00Z"
title: "Both remaining code gaps closed; only credentialled work and one scope decision left"
summary: "Closed gaps 8 and 12 on the Bangla newspaper platform, removed the last third-party request from every page carrying a face, corrected four wrong documentation claims, and refreshed the counted figures. Nothing unblocked remains in the codebase; bilingual is the one substantial feature left and needs a scope decision first."
keywords: ["newspaper", "laravel", "bangla", "epaper-editions", "redirects", "avatars", "mutation-testing", "documentation-drift"]
resume_focus: "Decide the scope of gap 11 (bilingual), or finish the two credentialled items"
repository: "newspaper"
branch: "main"
head: "1b306d0"
---

> A **snapshot** of where one session stopped, not a source of truth. Anything
> here that contradicts [`STATUS.md`](STATUS.md), [`DECISIONS.md`](DECISIONS.md)
> or [`CLAUDE.md`](../CLAUDE.md) is out of date — those are maintained, this is
> written once and superseded by the next session. `head` above is the commit
> before this file was updated.

# Where this was left

A Bangla-first newspaper platform on Laravel 13. **Tree is clean and pushed;
nothing is in flight.** Suite: **756 tests, 3,243 assertions**, nothing skipped
or risky. Lint, build and `storage/logs/laravel.log` all clean at the last run.

This session worked the launch checklist to its end. Every item that could be
closed from inside the repository now is.

## Read these first, and what matters in each

- `docs/STATUS.md` — the authoritative state. §"By the numbers" (verified
  against a fresh count, not remembered), §"Known gaps", and §"Where this was
  left" at the end, which is the shortest path to what remains and is ordered
  by value.
- `CLAUDE.md` — traps. Three were added this session and all three have cost
  time already: that redirects fire at 404 time and a numeric path segment can
  steal one, that drawn images are wordless but SVG is the exception, and that
  two `expectsOutputToContain()` on one line only match once.
- `docs/DECISIONS.md` — why things are shaped as they are. Sixteen entries
  added this session, covering the e-paper edition URL shape, the redirect
  resolver's placement, and the avatar palette.
- `docs/DEPLOY.md` — audited this session and clean: all 23 artisan commands
  it names exist, and its log paths match what the scheduler actually writes.

## What is actually left

**Two items, and neither needs code.**

`backup:sync` has never run against a real bucket — everything is proven
against a local directory standing in for the remote, but not credentials,
region resolution, path-style addressing, or the multipart upload a ~78 MB
archive takes, which is where the ETag stops being an MD5 and `verify()` falls
back to size. Six `BACKUP_OFFSITE_*` values in `.env`, then the three commands
in `STATUS.md` gap 5.

The two external watchers need a public hostname. The repo side is built and
was driven over real HTTP against a local listener. User chose **Better Stack**;
`DEPLOY.md` → "Setting the two watchers up" is copy-paste.

~~**One decision, and it is the only substantial feature left.**~~ **Gap 11 is
built, 6 September**, as the *translated edition* — the user chose that
reading of the three. Bangla is unprefixed, English is `/en`, an article in
either edition is its own row linked by `translation_of`, and the switcher
appears only where a published counterpart exists. What was deliberately left
out is listed in `STATUS.md` item 3. Topics and tags joined the edition later
the same day (`name_en` columns, `/en/topic` and `/en/tag`), and so did the
byline block, the archive, the e-paper, the newsletter, video and the photo
galleries. What is still out is the block-driven English front page and
`/live` — the chrome omits a link to any of them in English, decided by
`Route::has` rather than a list — and the 5xx error pages, which must stay
dependency-free.

~~**One small known defect, deliberately not fixed.**~~ **Fixed on 6 September**
— and it was two colours, not one: `#0891B2` fails as well at 3.68:1.
`App\Support\Contrast` darkens a section label only as far as WCAG AA needs.
The same fix then went to the site's four other colour labels, and that is
where the real finding was: **sixteen of the eighteen seeded category colours
fail AA on the dark surface**, against two on white. See `STATUS.md` § "Where
this was left" items 4 and 5 — both closed.

## Constraints the user set

- **This is a demo/portfolio product, not a real client.** The masthead,
  imprint and six static pages are a fictional identity that declares itself.
  Never invent an editor, publisher, address or phone — those are a legal
  requirement on a real Bangladeshi masthead.
- **Push to `main`.** Not a branch. The user confirmed every commit and push
  individually; they were never batched without asking.
- **Deletion is permanent** (their decision): comments stay attributable via
  `withTrashed()`, a deleted reader is told the truth and stays deleted.

## The habit that found everything real

Two habits, and between them they found every genuine defect this session.

**Break the thing to check the test can see it.** Thirty-two mutations across
five commits, each caught by the test that claimed to guard it — and **three
tests written in this session turned out to be incapable of failing**, with
two more assertion mechanisms failing for the wrong reason. If you write a
test here, mutate what it guards before believing it.

**Then drive the real thing over HTTP anyway.** Every defect the suite could
not see was found this way, with the tests already green: the dated-permalink
collision that silently serves the wrong article, and both halves of the
third-party avatar problem. Compile-time and test-time checks have repeatedly
passed while the runtime path was wrong.

## Wrong paths you are likely to retry

Each of these looked correct and was not:

- **Middleware for the `redirects` table.** `STATUS.md` and `PLAN.md` both
  called it that. `/{category}` is constrained `.*`, so nothing reaches a
  routing-level 404 — a `Route::fallback()` never fires, and middleware would
  have to run before the router and query on every request to the site.
- **Comparing `$request->url()` against a canonical URL that carries a query
  parameter.** `url()` drops the query string, so the comparison never matches
  and the redirect loops. Compare against the *requested* value instead.
- **`assertSee()` on an identifier that also appears in its own href.** An
  edition key, a slug, an id — the assertion is green with nothing rendered.
  Assert the element's text.
- **Chaining `expectsOutputToContain()` twice for substrings on one line.**
  Mockery routes a `doWrite` call to the first matching expectation only.
  Assert on `Artisan::output()` instead.
- **`$model->increment()` on a partially-selected row.** Strict mode throws on
  the attribute that was never loaded — it broke fourteen tests at once, which
  is a confusing way to learn it.
- **Assuming a designed palette is accessible.** Nine of the ten category
  colours clear WCAG AA against white; `#DB6B00` is 3.43:1. Compute contrast,
  do not trust the comment.
- **Believing "By the numbers".** Six of its figures had drifted, in the one
  table whose whole claim is that they were counted. Parse it and compare
  against a fresh count.

## Machine-local state (this box only)

- **`.env` sets `MAIL_MAILER=smtp` against a real account.** Anything calling
  `Mail::` from tinker or a scratch script sends live mail. The suite is safe —
  `phpunit.xml` pins the `array` mailer.
- **`admin@newspaper.test` does not exist here** although `UserSeeder` creates
  it and the README lists it. `editor@newspaper.test` and
  `reader@newspaper.test` work (password `password`). The real admin is a
  personal address.
- **Demo data has drifted from a fresh seed**: five e-paper issues where the
  seeder draws six, plus a few extra comments and galleries, all from manual
  verification of admin delete paths. Re-seeding does not restore them.
- **Seeded ads are active**, six of six. `STATUS.md` and a `MediaSeeder`
  docblock claimed the opposite for a long time; both are corrected, and it
  means the Lighthouse and CLS figures were measured against filled slots.
- **Headless Chrome is available** (`/bin/google-chrome`) and the box has
  Bengali system fonts, so rendering can be checked with a screenshot rather
  than reasoned about. That is how the avatar work was verified.
- Disk was at 97% (8.8 GB free) at the last check. Relevant if a full
  `backup:run` including the uploads archive is attempted.
- `ad-impressions.js` still has **no automated coverage** — there is no JS test
  runner here and adding one for a single module was judged not worth it.

## Verification performed

Full suite after every change, plus: twenty-nine mutations; HTTP probes of
every e-paper edition URL shape, every redirect shape, and every page carrying
a face; Chrome screenshots of the avatar at 88/40/32px and of a real comment
thread; a contrast computation over the whole category palette; an audit of
`DEPLOY.md`'s commands, log paths and file paths; and a parse-and-compare of
every figure in "By the numbers" against a fresh count — drift count zero.

Probe data was removed after each run: the temporary e-paper edition, the
imported redirect rules, and nothing else touched.

No failures are outstanding.
