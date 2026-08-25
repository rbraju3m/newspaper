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

Before adding a `create()`/`update()`/`fill()` call, check the model's
`$fillable`. This bug class appeared five times.

### Cached models need allow-listing

`config/cache.php` → `serializable_classes` is an explicit list. Adding a model
to a cached payload without adding it there produces a `TypeError` on the *next*
request, not the one that wrote it.

### `getOriginal()` applies casts

Inside model events, `getOriginal('status')` returns the **enum**, not the
stored string. Compare against the enum, or use `getRawOriginal()`.

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

There is no real test suite yet (Phase 7). `php artisan test` currently
**fails**: `phpunit.xml` uses SQLite `:memory:` and `pdo_sqlite` is not
installed here. Fix that first if you are adding tests —
`sudo apt install php8.4-sqlite3`, or repoint `phpunit.xml` at a MySQL test
database. Until then, at minimum:

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
