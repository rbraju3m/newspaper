# দৈনিক সংবাদ — Bangla Newspaper Platform

A Bangla-first news portal built on Laravel 13, designed from an analysis of ten
Bangladeshi and English mastheads. Server-rendered for SEO, installable as a
PWA, and built to stay readable on the slow mobile connections most of its
audience is on.

**Status:** Phases 0–5 complete and running against a live database.
See [`docs/STATUS.md`](docs/STATUS.md) for exactly where things stand.

---

## Stack

| | |
|---|---|
| Framework | Laravel 13.26 (PHP 8.4) |
| Templating | Blade, server-rendered |
| CSS | Tailwind CSS 4 (`@theme` tokens, no config file) |
| JS | Alpine.js 3 — no build-time framework |
| Database | MySQL 8 (utf8mb4) |
| Assets | Vite 8, self-hosted fonts |
| Auth | Laravel session auth + Socialite (Google, Facebook) |

Deliberately **not** a SPA. A news site lives on search traffic and cold first
loads; server-rendered HTML with a light sprinkle of Alpine beats shipping a
framework to every reader.

---

## Quick start

```bash
git clone <repo> newspaper && cd newspaper

composer install
npm install

cp .env.example .env
php artisan key:generate

# Create the database, then set DB_* in .env
mysql -u root -p -e "CREATE DATABASE newspaper CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

php artisan migrate --seed      # ~20s: 55 categories, 374 articles, 107 comments
php artisan storage:link
npm run build

php artisan serve
```

Open <http://127.0.0.1:8000>.

### Seeded logins

| Email | Role | Password |
|---|---|---|
| `admin@newspaper.test` | Admin | `password` |
| `editor@newspaper.test` | Editor | `password` |
| `reader@newspaper.test` | Reader | `password` |

Demo data only — remove before any public deployment.

---

## Serving under Apache

The global `<Directory /var/www/>` block on most Ubuntu installs sets
`AllowOverride None`, which makes Laravel's `public/.htaccess` inert: the
homepage loads and every other URL 404s. Use a vhost.

```bash
sudo cp docs/newspaper.local.conf /etc/apache2/sites-available/
sudo a2ensite newspaper.local
echo "127.0.0.1 newspaper.local" | sudo tee -a /etc/hosts
sudo systemctl reload apache2
```

Then set `APP_URL=http://newspaper.local` and run `php artisan config:clear`.

Apache runs as `www-data` and needs write access to `storage/` and
`bootstrap/cache/`. If you cannot change ownership, an ACL works without sudo:

```bash
setfacl -R  -m u:www-data:rwX storage bootstrap/cache
setfacl -R -d -m u:www-data:rwX storage bootstrap/cache
```

---

## Layout

```
app/
├── Enums/            ArticleStatus, ArticleType, CommentStatus, HomeBlockType, UserRole
├── Http/
│   ├── Controllers/
│   │   ├── Site/     public site (19)
│   │   ├── Admin/    CMS (12)
│   │   ├── Auth/     login, register, reset, verify, OAuth
│   │   └── Account/  reader profile, bookmarks, history, preferences
│   ├── Middleware/   EnsureUserIsStaff
│   └── Requests/     form requests, grouped by area
├── Models/           23 Eloquent models
├── Policies/         Article, Comment, User
├── Services/         AdService, ArticleQuery, HomepageService, ImageService
├── Support/          Bangla (digits, dates, Bengali calendar, relative time)
└── View/Composers/   LayoutComposer, AdminComposer

resources/
├── css/app.css       design tokens + editorial prose styles
├── js/
│   ├── stores/       theme, reader, pwa, toast
│   └── components/   ticker, share, infinite-scroll, live-blog, editor, …
└── views/
    ├── layouts/      site, auth, admin
    ├── components/   article/, home/, ui/, form/, comment/, admin/
    ├── site/         public pages
    ├── admin/        CMS screens
    └── partials/     header, footer, mega-menu, app-chrome

routes/
├── web.php           public site (auth.php is required from the top)
├── auth.php          login, account, comments
└── admin.php         CMS
```

**Route order matters.** Category paths are materialised and contain slashes
(`khela/cricket`), so the category route must accept `.*` — which matches
everything. It and the article route are registered **last**, and `auth.php` is
required from the **top** of `web.php`, or `/login` resolves as a category slug.

---

## Common commands

```bash
php artisan migrate:fresh --seed   # rebuild + reseed
php artisan cache:clear            # after changing settings or layout blocks
npm run dev                        # Vite with HMR
npm run build                      # production assets

php artisan route:list --except-vendor
php artisan tinker
```

Mail is `MAIL_MAILER=log` in development — verification and reset links land in
`storage/logs/laravel.log`.

---

## Documentation

| Doc | What's in it |
|---|---|
| [`docs/PLAN.md`](docs/PLAN.md) | Competitive analysis of the ten reference sites, design system, IA, phase plan |
| [`docs/DECISIONS.md`](docs/DECISIONS.md) | Why things are built the way they are — the non-obvious calls |
| [`docs/STATUS.md`](docs/STATUS.md) | What's done, what's left, known gaps |
| [`CLAUDE.md`](CLAUDE.md) | Conventions and traps for anyone (or any agent) working in this repo |
