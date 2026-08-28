---
artifact_contract: "ce-handoff/v1"
created_at: "2026-08-28T10:50:21Z"
title: "Newspaper launch checklist closed down to one blocked item"
summary: "Closed the last test-coverage gaps and four launch gaps on the Bangla newspaper platform, fixed eleven app defects and four tests that could not fail; only the off-site backup bucket remains and it needs credentials."
keywords: ["newspaper", "laravel", "bangla", "test-harness", "lazy-loading", "backups", "branding", "ad-impressions"]
resume_focus: "Prove backup:sync against a real S3 bucket, or pick up gap 8 / gap 12"
repository: "newspaper"
branch: "main"
head: "16b85d8"
---

> A **snapshot** of where one session stopped, not a source of truth. Anything
> here that contradicts [`STATUS.md`](STATUS.md), [`DECISIONS.md`](DECISIONS.md)
> or [`CLAUDE.md`](CLAUDE.md) is out of date — those are maintained, this is
> written once and superseded by the next session. `head` above is the commit
> before this file was added.

# Where this was left

A Bangla-first newspaper platform on Laravel 13. The session worked through the
launch checklist in `docs/STATUS.md` from the top, item by item, as the user
named each one. Everything that could be closed from inside the repository now
is. **Tree is clean and pushed; nothing is in flight.**

Suite: **694 tests, 3,067 assertions**, nothing skipped or risky. Lint, build
and `storage/logs/laravel.log` all clean at the last run.

## Read these first, and what matters in each

- `docs/STATUS.md` — the authoritative state. §"By the numbers" (counted, not
  remembered), §"Verification currently passing", §"Known gaps" (numbered; 4,
  14, 15 and 20 were closed this session), and §"Where this was left" at the
  end, which is the shortest path to what remains.
- `CLAUDE.md` — traps. The three added this session are the ones most likely to
  bite: strict mode not covering single-row fetches, session-fixation tests that
  cannot fail, and the branding/imprint rules.
- `docs/DECISIONS.md` — why things are shaped as they are. New entries cover
  client-side impressions, OAuth case 3, deletion semantics, and the single-rung
  srcset.
- `docs/DEPLOY.md` — §"Setting the two watchers up" is a copy-paste procedure
  that was executed and verified as written.

## What is actually left

**One launch item, and it is blocked on credentials rather than code.**
`backup:sync` has never run against a real bucket. Everything about it is proven
against a local directory standing in for the remote; unproven is the half only
a real endpoint has — credentials, region and endpoint resolution, path-style
addressing, and the multipart upload a 78 MB archive takes, which is where the
ETag stops being an MD5 and `verify()` falls back to size. Six `BACKUP_OFFSITE_*`
values in `.env`, then the three commands in `STATUS.md` gap 5.

**Two external watchers**, blocked on a public hostname. The repo-side half is
built and was verified end to end against a local HTTP listener: a good
`backup:run` requests `/ping/<token>` and exits 0, a broken one requests
`/ping/<token>/fail`, exits 1, and deletes the truncated dump. `/up` answers 200
healthy and 500 with the database on a dead port. User chose **Better Stack**.

**Two small, self-contained, unblocked gaps** if something cold is wanted:
gap 8 — `/epaper/{date}` resolves by date alone, so a second edition on the same
day is unreachable and which one you get is an unordered `firstOrFail()`;
gap 12 — the `redirects` table still has no middleware reading it.

## Constraints the user set

- **This is a demo/portfolio product, not a real client.** The user chose this
  explicitly. The masthead, imprint and six static pages are a fictional
  identity that declares itself. Do not invent an editor, publisher, address or
  phone — those are a legal requirement on a real Bangladeshi masthead.
- **Push to `main`.** Not a branch. The user confirmed every commit and push
  individually; they were never batched without asking.
- **Deletion is permanent** (their decision, from three options): comments stay
  attributable via `withTrashed()`, a deleted reader is told the truth and stays
  deleted.

## The habit that found most of the defects

Almost everything real was found by **breaking something to check the test could
see it**. Green was not treated as evidence until a mutation proved it. Four
tests turned out to be incapable of failing, including one written in this
session. If you write a test here, mutate the thing it guards.

`CLAUDE.md` carries a repeatable vendor-patch sweep for lazy loads; the suite
passes with that patch applied, which is what makes it re-runnable.

## Wrong paths you are likely to retry

Each of these looked correct and was not:

- **Session rotation via before/after `session()->getId()`** cannot fail —
  Laravel's test client does not carry cookies between calls, so the ids always
  differ. Use `TestCase::continuingSession()` and assert a control first.
  Also: `SessionGuard::updateSession()` calls `regenerate(true)` itself, so
  deleting a controller's explicit `regenerate()` does not reintroduce fixation.
- **Proving no-lazy-load by rendering a page** whose models come from a cache
  cannot fail. `unserialize()` fires no `retrieved`, so
  `AppServiceProvider::closeTheLazyLoadingHole()` never stamps those models.
  Assert against a freshly queried model.
- **`assertDatabaseMissing` on a differently-cased email** cannot fail —
  `users.email` is `utf8mb4_unicode_ci`. Read the stored value back instead.
- **`dfp.portal.gov.bd`** (the Bangladeshi newspaper register) serves a default
  Kubernetes placeholder certificate. It was deliberately not read over a
  bypassed connection. The masthead check therefore covered ~5% of the register;
  that limit is recorded in `config/site.php` and `STATUS.md`.
- **`Article::imageSrcset`'s docblock** claimed it returns null rather than a
  single-candidate srcset. It never did, and emitting one is correct here —
  6.5 KB WebP against a 16.2 KB JPEG. Comment corrected in three places.
- Reading only PHPUnit's `failures` and not its `errors` hid two findings during
  the lazy-load sweep.

## Machine-local state (this box only)

- **`.env` sets `MAIL_MAILER=smtp` against a real account.** Anything calling
  `Mail::` from tinker or a scratch script sends live mail. The suite is safe —
  `phpunit.xml` pins the `array` mailer. The README now says this loudly.
- **`admin@newspaper.test` does not exist here** although `UserSeeder` creates
  it and the README lists it. `editor@newspaper.test` and
  `reader@newspaper.test` work (password `password`). The real admin is a
  personal address. An editor reaches everything under `manage-taxonomy` but
  not ads, pages, users or settings.
- **Demo data has drifted from a fresh seed**: five e-paper issues where the
  seeder draws six, and a few extra comments and galleries — all from manual
  verification of admin delete paths. Re-seeding does not restore them; the
  imagery seeders are idempotent. Documented in `STATUS.md`.
- Four extra database dumps (~2.2 MB) sit in `storage/app/backups/database/`
  from testing the heartbeat. Gitignored, auto-pruned at 14 days, harmless.
- Disk is at 97% (8.8 GB free). Relevant if a full `backup:run` including the
  78 MB uploads archive is attempted.
- A stubbed-browser harness for `ad-impressions.js` was written to a scratch
  path and is not in the repo. The JS has **no automated coverage** — there is
  no JS test runner here and adding one for one module was judged not worth it.

## Verification performed

Full suite after every change. Beyond that: mutation checks on every guard added
(each fix fails its test when reverted); a crawl of all 48 reachable GET routes
against seeded data, public and admin, signed in; the ad impression beacon and
the backup heartbeat both driven over real HTTP; `/up` checked with
`APP_DEBUG=false` because with debug on the route rethrows and shows something
no monitor ever sees.

No failures are outstanding.
