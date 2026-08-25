# Newspaper Portal — Master Plan

**Stack:** Laravel 12 · Blade (SSR) · Tailwind CSS 4 · Alpine.js · MySQL 8 · Apache
**Content language:** Bangla-first, bilingual-ready (i18n scaffolding from day one)
**Auth scope:** Full reader accounts + staff roles

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

Auth:    /login /register /logout /forgot-password /reset-password
         /verify-email /auth/{provider}/redirect|callback
Reader:  /account  /account/bookmarks  /account/history
         /account/preferences  /account/comments
Admin:   /admin/...
```

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
| `menus` / `menu_items` | header/footer menu builder |
| `redirects`, `activity_log` | ops |

---

## 5. Implementation Phases

### Phase 0 — Foundation
Laravel 12 install · MySQL database + `.env` · Vite + Tailwind 4 + Alpine · Bangla fonts self-hosted · design tokens · `BanglaDate` / `BanglaNumber` helpers · base layout (header, nav, footer) · Apache vhost.

### Phase 1 — Data layer
All migrations, models, relationships, enums, factories, seeders with realistic Bangla demo content (30+ categories, 200+ articles, authors, tags, topics).

### Phase 2 — Public site
Homepage with all blocks · category & sub-category pages · article detail (share bar, font control, related, tags, author box) · latest/popular · search · date archive · video hub · photo galleries · author pages · static pages · RSS.

### Phase 3 — Auth & reader features
Register/login/logout · email verification · password reset · Google + Facebook OAuth · profile & avatar · bookmarks · reading history · comments + replies + moderation queue · reactions · newsletter subscribe/verify · personalised "আপনার জন্য" feed.

### Phase 4 — Admin CMS
Dashboard (traffic, top stories, pending comments) · article editor (rich text, image upload w/ crop, scheduling, SEO preview) · media library · categories/tags/topics CRUD · homepage layout manager · users & roles · comment moderation · ads manager · polls · e-paper upload · settings · activity log.

### Phase 5 — Interactivity & polish
Dark mode · reading-progress bar · infinite scroll · live ticker (polling/SSE) · live blog · sticky share rail · skeleton loaders · toast notifications · PWA (offline last-read, install prompt) · push notifications for breaking news · keyboard shortcuts.

### Phase 6 — SEO & performance
`NewsArticle` JSON-LD · Open Graph + Twitter cards · XML sitemap + **Google News sitemap** · canonical/hreflang for BN/EN · responsive images WebP/AVIF + `srcset` · Redis/file cache for homepage blocks · queue for views/notifications · Lighthouse ≥ 90 · Core Web Vitals budget (LCP < 2.5s, CLS < 0.1).

### Phase 7 — Hardening & launch
Rate limiting, CSRF/XSS/SQLi review, comment spam control, backups, error tracking, feature/browser tests, deploy runbook.

---

## 6. Success Criteria
- Lighthouse ≥ 90 on home + article, mobile
- LCP < 2.5s, CLS < 0.1 with ads rendered
- WCAG 2.1 AA
- Reader can register, verify, log in, bookmark, comment, and see history — end to end
- Editor can publish a story from admin to homepage in under 2 minutes
