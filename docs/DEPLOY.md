# Deploying

A first deploy to a fresh Ubuntu box, and the shorter loop for every deploy
after it. Written against what this application actually needs — the
requirements are unusually specific in three places, and each of them fails
quietly rather than loudly.

Read alongside [`STATUS.md`](STATUS.md) for what is still open before a public
launch. One of those is not a deployment step and is not covered here: real
branding.

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

### Two clocks, and they must agree

`APP_TIMEZONE` is `Asia/Dhaka` and `DB_TIMEZONE` is `+06:00`. **They have to
match.** MySQL converts every `TIMESTAMP` column on the way in and out using
the *session* zone, so the two settings are two halves of one decision.

This was a real bug rather than a preference. MySQL on the development box runs
with a system zone of `+06` while PHP ran on UTC, so the two clocks disagreed:
`bookmarks` and `comment_likes` stamp `created_at` with `useCurrent()` — which
is MySQL's clock — while every other row was stamped by PHP's. Two rows written
in the same instant were six hours apart.

`DB_TIMEZONE` is pinned rather than left to the server's system zone precisely
so this cannot vary with the box you deploy on. If you deploy somewhere the
newsroom is not in Dhaka, change both together.

**Timestamps written before the change keep their wall-clock reading and change
meaning.** A row saying `11:00` was 11:00 UTC and now reads as 11:00 Dhaka. In
practice nothing visible moves — the displayed time and every relative time
("৩৮ মিনিট আগে") are computed from the same wall clock and are unchanged — but
the ISO strings in feeds and JSON-LD now carry `+06:00`, so historical items
shift six hours in absolute terms. Do this before real publishing begins and
the question does not arise.

### Scheduled publishing depends entirely on cron

`articles:publish-due` runs every minute and moves a `scheduled` article to
`published` once its time has passed. That is the whole mechanism — there is no
other trigger — so **if the cron entry below is missing, scheduling silently
does nothing** and stories sit unpublished with no error anywhere.

The admin dashboard's `scheduled_due` count is the canary: it counts articles
whose time has passed and which are still scheduled. On a healthy install it is
zero. If it is climbing, `schedule:run` is not running.

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

This is not optional. Three things run through the scheduler — publishing due
articles every minute, the nightly backup, and the morning error digest — and
without this line none of them fire. `php artisan schedule:list` tells you what
is registered; it cannot tell you whether cron is calling it. Check two things
after a deploy: that `storage/logs/backup.log` grows overnight, and that the
dashboard's `scheduled_due` count is not climbing.

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
means data loss. Take a backup first, every time — `php artisan backup:run`,
below.

---

## Backups

```bash
php artisan backup:run              # both halves, verified
php artisan backup:run --database   # dump only
php artisan backup:run --files      # uploads only
php artisan backup:run --keep=30    # change the retention window
```

Writes to `storage/app/backups/`, which is outside the document root. The
command refuses to write anywhere under `storage/app/public`, because
`storage:link` symlinks that tree into `public/` — a dump written there is one
guessed filename away from being downloaded by anyone.

**Two halves, and you need both.** `uploads/` — originals plus the whole WebP
derivative ladder — is in neither the SQL dump nor git. A restore from the
database alone gives you a newspaper whose every image is a broken link.

**It verifies before reporting success.** This matters more than it sounds. A
truncated dump is a *valid* gzip file of a plausible size: `gzip -t` passes it
without complaint, and it restores into a half-empty database. The only cheap
proof the dump finished is mysqldump's own closing `Dump completed` line, so
that is what is checked, along with the gzip integrity and a size floor.
Anything that fails is **deleted**, so a bad backup never sits there looking
like a good one.

The nightly run is registered in `routes/console.php` for 03:00 and appends to
`storage/logs/backup.log`. It needs the cron entry above to fire at all.

### What this does not do

Backups are written to the same disk as the database they came from. That
survives a bad migration, a bad deploy and a bad `DELETE`; it does not survive
the server. **Getting the files off this machine is still a manual job** — an
`rsync` to another host, or a sync to object storage:

```cron
30 3 * * * rsync -az --delete /var/www/newspaper/storage/app/backups/ backup@elsewhere:/srv/newspaper/
```

There is no monitoring: nothing tells you the backup stopped running. Until
error tracking exists (see `STATUS.md`), check `backup.log` on a schedule you
will actually keep.

### Restoring

```bash
# Database. --add-drop-table is on by default, so this replaces what is there.
gzip -cd storage/app/backups/database/newspaper-YYYY-MM-DD-HHMMSS.sql.gz \
  | mysql --default-character-set=utf8mb4 -u newspaper -p newspaper

# Uploads. The archive holds relative paths, so it restores onto any box.
tar -xzf storage/app/backups/files/uploads-YYYY-MM-DD-HHMMSS.tar.gz \
  -C storage/app/public

php artisan optimize:clear
```

Then check the restore rather than assuming it: compare a row count against
what you expected, open a story with an image on it, and search a Bangla term.
A restore verified only by "the command exited 0" is the same mistake as an
unverified backup.

---

## Error alerting

Nothing is pushed until you configure somewhere to push to. Blank is the
default, and a blank install behaves exactly as it did before.

```dotenv
ERROR_ALERT_EMAIL=oncall@example.com
ERROR_ALERT_WEBHOOK=https://hooks.slack.com/services/…
ERROR_ALERT_THROTTLE=60
ERROR_ALERT_MAX_PER_HOUR=20
```

Both channels can be set; either can be left blank. Slack and Discord webhook
URLs are both understood — they take different payload fields and the right one
is chosen from the host.

**Everything reportable is recorded regardless**, as one JSON object per line in
`storage/logs/errors-YYYY-MM-DD.log`, kept for `ERROR_LOG_DAYS` days. That file
is what the digest reads, and it is a file rather than a table on purpose: a
database outage is the failure you most want recorded, and it is exactly when
the database cannot record it. The throttle state is in the *file* cache for the
same reason.

**Alerts are throttled, hard.** One per distinct fault per hour, under a ceiling
of twenty an hour across all faults. The fingerprint is the exception class,
file and line — not the message, which usually carries an id and would make one
broken line look like a thousand problems. A thousand alerts is
indistinguishable from silence, and it takes the mail server with it.

Laravel already declines to report 404s, validation failures, 419s and auth
failures before any of this runs, so those never alert.

### The morning digest

```bash
php artisan errors:digest                 # yesterday, printed
php artisan errors:digest --days=7        # the week
php artisan errors:digest --email=you@example.com
```

Scheduled for 07:00, and skipped entirely when `ERROR_ALERT_EMAIL` is blank.
This is the counterpart to the throttle: the alert says a thing broke once, and
the digest says it has been breaking three hundred times a day since Tuesday.

### Two things to know

**Alerts are sent synchronously from the exception handler**, because this
deployment runs no queue worker. The webhook timeout is five seconds for that
reason. If a worker ever exists, dispatching the send is the upgrade.

**Nothing watches the watcher.** If mail delivery breaks, the alert about it
goes to the same broken mail. `storage/logs/laravel.log` records
`ErrorAlerter (email) failed` when a channel throws, and that is the only
signal. An external uptime check on `/up` is the honest complement to this and
is not in scope here.

---

## Rate limits

Defined as named limiters in `AppServiceProvider::registerRateLimiters()` and
applied as `throttle:<name>` in the route files. `php artisan route:list -v`
shows which route wears which.

| Limiter | Limit | Keyed by | Covers |
|---|---|---|---|
| `newsletter` | 5/hour | IP | subscribing — unauthenticated, writes a row, does a DNS lookup per attempt |
| `vote` | 10/min | IP | poll votes; a guest's fingerprint is IP + user agent, so the IP is the real control |
| `share` | 30/min | IP | the share counter, incremented by `sendBeacon` |
| `search` | 30/min | IP | Bangla FULLTEXT against a `longText` column |
| `polling` | 60/min | IP | the live-blog and ticker endpoints — roughly twenty open tabs |
| `engagement` | 60/min | user | likes, reports, bookmarks, reading progress |
| `comment-writes` | 20/min | user | posting and editing comments |
| `account` | 10/min | user | password change, profile edit, account deletion |
| `admin` | 120/min | user | the whole admin surface, as a backstop |

**Authenticated limits are keyed by user id, not IP.** A newsroom sits behind
one NAT; an IP bucket would put the whole desk in one editor's allowance, and
the first person to work quickly would lock everyone else out.

**`logout` is deliberately unlimited.** It takes an authenticated request to
reach and destroys state rather than creating it; throttling it only leaves
somebody unable to end their own session.

These are a backstop, not the whole defence. Anything needing a limit tight
enough for a person to notice gets it in the controller, where it can explain
itself in Bangla — `CommentController` refuses a second comment inside a minute
that way, and the middleware only stops the request that never should have
arrived.

### What this does not do

There is **no global limiter** on the `web` group. One would have to be loose
enough for a shared office connection and would then be too loose to matter;
volumetric limiting belongs at the reverse proxy or CDN, not in PHP.

A throttled request gets the Bangla 429 page in `resources/views/errors/`,
which reads the `Retry-After` header and tells the reader how long to wait.

If a limit turns out to be wrong for real traffic, the numbers are all in that
one method. Raise it there rather than removing the middleware.

---

## Error pages

`resources/views/errors/` carries a Bangla page for every code a reader can
reach. They come in two families with opposite requirements, and the split is
the only thing here worth remembering.

**4xx — the application is healthy.** `404`, `403`, `419`, `429` and a `4xx`
fallback extend the site layout, so a reader keeps the masthead and the nav and
is one click from somewhere useful. The 404 carries a search box: a reader who
lands there was looking for something specific, and offering only the front
page throws that away.

**5xx — it is not.** `500`, `503` and a `5xx` fallback are self-contained: no
layout, no composers, no database, no JavaScript, no webfonts, and their CSS is
inline. That is not tidiness. `layouts.site` builds its header and footer from
view composers that query the category tree, and `CACHE_STORE` is the database
— so at the moment a 500 renders, none of it can be relied on, and a layout
that throws while rendering an error page loses the error page.

Inline CSS rather than `@vite` for the same class of reason: `artisan down
--render="errors::503"` bakes a static file that is served without booting the
application, and a deploy that rebuilds assets between `down` and `up` would
leave it pointing at a hashed bundle that no longer exists.

Verified by rendering the 5xx views against a dead database, and over HTTP with
`APP_DEBUG=false DB_PORT=1`: Bangla, self-contained, and no stack trace.

`APP_DEBUG=false` is what makes the 500 page appear at all — with debug on you
get the stack trace instead, which is the other reason that setting is not
optional.
