# Deploying

A first deploy to a fresh Ubuntu box, and the shorter loop for every deploy
after it. Written against what this application actually needs — the
requirements are unusually specific in three places, and each of them fails
quietly rather than loudly.

Read alongside [`STATUS.md`](STATUS.md) for what is still open before a public
launch. Four of those items are not deployment steps and are not covered here:
real branding, backups, error tracking, and the timezone decision below.

---

## What will bite you

Take these before anything else. Each has already cost time, or is guaranteed
to.

### PHP 8.4 is a hard floor, not a preference

`App\Support\Html` — which every article, live entry and page body is sanitised
through — is built on `Dom\HTMLDocument`, and that class does not exist before
PHP 8.4. `composer.json` now says `^8.4` so the install refuses rather than the
application fataling on the first save. If a host offers "PHP 8.3+", that is not
the same answer.

### MySQL, and only MySQL

`Article::search()` uses `MATCH ... AGAINST` in boolean mode against a FULLTEXT
index. On any non-MySQL driver it **silently falls back to `LIKE`** — no error,
no log line, just quietly worse Bangla search. Do not deploy this on SQLite or
Postgres on the assumption that Eloquent papers over the difference.

Tested against MySQL 8.0. The tables must be InnoDB and the database
`utf8mb4` / `utf8mb4_unicode_ci`; a `latin1` database will mangle every Bangla
headline on the way in.

### `MAIL_MAILER=log` means nobody can ever register

Development uses the log mailer, and verification and reset links land in
`storage/logs/laravel.log`. Ship that setting and every reader who registers
waits forever for an email that was written to a file on your server.

While you are there: the newsletter box validates with `email:rfc,dns`, which
puts a blocking DNS lookup in front of every submission and **rejects
`@example.com` outright** (egulias refuses the RFC 2606 reserved domains
whatever DNS says). Test it with a real domain.

### Decide the timezone before the first story publishes

`config/app.php` sets `'timezone' => 'UTC'`. Timestamps are stored and rendered
in it, so `@bntime` currently prints UTC — six hours behind Dhaka. For a
Bangladeshi newspaper that is wrong on every byline and every live-blog entry.

Changing it after publication reinterprets nothing (the stored values stay UTC)
but shifts what every existing timestamp *displays*, so make the call before
launch, not after:

```php
// config/app.php
'timezone' => 'Asia/Dhaka',
```

This is deliberately not changed in the repo — it is a product decision, and
the tests assert against the current behaviour.

### Scheduled articles do not publish themselves

An article saved with status `scheduled` and a future `published_at` stays
invisible until a human sets it to `published`. Nothing flips it: there are no
queued jobs and no scheduled tasks in this application at all.

The admin dashboard counts overdue ones (`scheduled_due`) so the newsroom
notices, and that is the whole mechanism today. Install the cron entry below
anyway — it costs nothing and is the hook an auto-publish command would use —
but do not promise an editor that scheduling works unattended.

---

## First deploy

### 1. Server packages

```bash
sudo apt update
sudo apt install -y apache2 mysql-server \
    php8.4 libapache2-mod-php8.4 \
    php8.4-mysql php8.4-mbstring php8.4-xml php8.4-gd php8.4-curl php8.4-zip \
    unzip git
```

Why each of the non-obvious ones:

| Extension | Needed for |
|---|---|
| `mysql` (pdo_mysql) | the only supported driver — see above |
| `mbstring` | Bangla string handling throughout `App\Support\Bangla` |
| `xml` (dom, libxml) | `Dom\HTMLDocument`, the HTML sanitiser |
| `gd` | `ImageService` — the WebP derivative ladder. **Must be built with WebP support**; `imagewebp()` missing means every upload stores an original and no ladder |
| `curl` | Socialite's OAuth calls |

Confirm GD can actually write WebP before going further — this is the one that
tends to be missing:

```bash
php -r 'var_dump(function_exists("imagewebp"), gd_info()["WebP Support"] ?? false);'
```

Raise the upload limits. The application and its UI both promise 8 MB, and PHP
ships with 2 MB, so anything larger is discarded before Laravel ever runs:

```ini
; /etc/php/8.4/apache2/php.ini
upload_max_filesize = 8M
post_max_size = 10M
memory_limit = 256M
```

### 2. Database

```bash
sudo mysql -e "CREATE DATABASE newspaper CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'newspaper'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME';"
sudo mysql -e "GRANT ALL PRIVILEGES ON newspaper.* TO 'newspaper'@'127.0.0.1';"
```

Scope the grant to the one database. The application never needs `CREATE
DATABASE`, and a scoped user is what stops a stray `migrate:fresh` pointed at
the wrong `DB_DATABASE` from taking anything else with it.

### 3. Code and dependencies

```bash
sudo git clone git@github.com:rbraju3m/newspaper.git /var/www/newspaper
cd /var/www/newspaper

composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

`--no-dev` matters: it leaves Pint, PHPUnit and Faker off the server. `npm run
build` writes `public/build`, which is content-hashed and safe to cache
forever — the vhost below does exactly that.

### 4. Configuration

```bash
cp .env.example .env
php artisan key:generate
```

Then edit `.env`. `.env.example` is written for this application rather than
being Laravel's stock file, so the blanks are the real list. The ones with no
sane default: `DB_*`, `MAIL_*`, `APP_URL`, and the `SITE_*` imprint block that
feeds the footer and the JSON-LD publisher.

`APP_URL` must be `https://` — `AppServiceProvider` calls
`URL::forceScheme('https')` in production, so an `http://` value gives you
redirect loops or mixed content.

### 5. Permissions

Apache runs as `www-data` and needs to write exactly two trees:

```bash
sudo chown -R deploy:www-data /var/www/newspaper
sudo find /var/www/newspaper -type d -exec chmod 750 {} \;
sudo find /var/www/newspaper -type f -exec chmod 640 {} \;
sudo chmod -R 770 storage bootstrap/cache
```

Nothing else should be group-writable. `.env` in particular:

```bash
sudo chmod 640 .env && sudo chown deploy:www-data .env
```

### 6. Schema and content

```bash
php artisan migrate --force
php artisan storage:link
```

`--force` is required: `migrate` refuses to run unprompted in production.

Then decide what content the site starts with. **Do not run
`db:seed`** — `DatabaseSeeder` writes 374 invented articles and three logins
whose password is the word `password`. Either seed the structure only:

```bash
php artisan db:seed --class=CategorySeeder   # the 55-category Bangla tree
php artisan db:seed --class=SiteSeeder       # settings, homepage layout, static pages
```

…or, if this box was ever seeded in full, purge it:

```bash
php artisan demo:purge --dry-run    # read the plan first
php artisan demo:purge
```

`demo:purge` keeps the taxonomy, layout, settings and pages, and deletes the
demo content and every non-admin user. It refuses to delete every account, so
create your real admin first:

```bash
php artisan tinker --execute='App\Models\User::create([
    "name" => "সম্পাদক",
    "email" => "you@example.com",
    "password" => "a-long-passphrase",
    "role" => App\Enums\UserRole::Admin,
])->forceFill(["email_verified_at" => now()])->save();'
```

Two details, both verified rather than assumed. The password goes in **plain**:
`User` casts it `hashed`, so it is hashed on the way to the column (and calling
`Hash::make()` yourself is harmless too — the cast guards with
`Hash::isHashed()`). And `email_verified_at` needs `forceFill`, because it is
guarded; without it the account exists and cannot do anything that requires a
verified address.

### 7. Web server

The repo ships a vhost at [`newspaper.local.conf`](newspaper.local.conf). It is
written for local use; for production copy it, change `ServerName` and
`DocumentRoot`, and add TLS. Everything else in it is deployment-relevant and
worth keeping:

- `AddType image/webp` — Ubuntu's `/etc/mime.types` has no WebP entry, so
  without it the entire derivative ladder is served with no `Content-Type`
- the `mod_deflate` block — uncompressed, the homepage document alone is 276 KB
  and cost ~1.6s and 16 Lighthouse points
- the immutable `Cache-Control` on `/build` and `/storage`

```bash
sudo a2enmod deflate expires headers rewrite ssl
sudo a2ensite newspaper
sudo certbot --apache -d example.com -d www.example.com
sudo systemctl reload apache2
```

The global `<Directory /var/www/>` block on Ubuntu sets `AllowOverride None`,
which is why a per-project vhost is needed at all — Laravel's
`public/.htaccess` rewrites are inert without it.

### 8. Cron

```cron
* * * * * cd /var/www/newspaper && php artisan schedule:run >> /dev/null 2>&1
```

Nothing is scheduled today (see above). Install it so the hook exists.

### 9. Warm the caches

```bash
php artisan optimize     # config, routes, views, events
```

Verified: no `env()` call exists outside `config/`, and the route files contain
no route-level closures, so both caches are safe here. `optimize` must be re-run
after **every** change to `.env` or to anything in `config/` — a cached config
ignores the file you just edited.

---

## Every deploy after the first

```bash
cd /var/www/newspaper
php artisan down --render="errors::503"

git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize

php artisan up
```

If a release changes `App\Support\Html::ALLOWED`, follow it with:

```bash
php artisan content:sanitize
```

Stored bodies were cleaned against the *old* allow-list and nothing re-cleans
them on its own.

If a release changes `ImageService::WIDTHS`:

```bash
php artisan media:backfill
```

---

## Verifying a deploy

Compile-time checks have repeatedly passed here while the runtime path was
broken. Exercise it.

```bash
curl -sI https://example.com/up | head -1              # 200, the health route
curl -s -o /dev/null -w '%{http_code}\n' https://example.com/
curl -s -o /dev/null -w '%{http_code}\n' https://example.com/rss
curl -s -o /dev/null -w '%{http_code}\n' https://example.com/sitemap.xml
curl -s -o /dev/null -w '%{http_code}\n' https://example.com/news-sitemap.xml
```

Then, by hand and signed in:

1. **Search a Bangla term.** Results mean the FULLTEXT index built. No results
   for a word you can see on the homepage means you are on the `LIKE` fallback
   or a non-MySQL driver.
2. **Upload an image.** Check the media library shows a derivative ladder, not
   just an original — that is `imagewebp()` reporting for duty.
3. **Register a reader.** The verification mail must arrive in an inbox, not in
   `storage/logs/laravel.log`.
4. **Publish a story with an image**, and confirm it appears on the homepage.
5. **Read `storage/logs/laravel.log`.** It should be empty.

A cold homepage is measurably slower than a warm one — roughly 0.38s against
0.12s locally, because the homepage cache is built on first request. Hit `/`
once yourself after `optimize` so a reader is not the one paying for it.

### Two production-only behaviours

Both are invisible in development, and both are worth knowing before you
diagnose something in the dark.

**Strict mode is off.** `Model::shouldBeStrict(! app()->isProduction())`. Lazy
loading, mass-assigning a guarded attribute and reading an unloaded attribute
all throw locally and all pass silently in production. A missing eager load
becomes an N+1 query instead of an exception — so a page that works in
production but throws locally is the *local* environment being right.

**`APP_DEBUG=false` is not optional.** With it on, a stack trace on any error
exposes `.env` contents, including `DB_PASSWORD` and `APP_KEY`.

---

## Rollback

```bash
php artisan down
git checkout <previous-tag>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan optimize
php artisan up
```

Migrations are the part that does not roll back cleanly. `migrate:rollback`
runs the `down()` methods, which for anything that dropped or rewrote a column
means data loss. Take a dump first, every time:

```bash
mysqldump --single-transaction --default-character-set=utf8mb4 newspaper \
  | gzip > ~/newspaper-$(date +%F-%H%M).sql.gz
```

`storage/app/public/uploads` is not in that dump and is not in git. It needs its
own backup, and no automated one exists yet — see `STATUS.md`.
