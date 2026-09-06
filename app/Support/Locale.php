<?php

namespace App\Support;

use Illuminate\Support\Facades\App;

/**
 * Which edition a request is in, and how to address the other one.
 *
 * The site is Bangla-first and Bangla is unprefixed: `/khela/cricket` is the
 * Bangla section and `/en/khela/cricket` is its English counterpart. That
 * asymmetry is deliberate — every existing URL, every indexed page and every
 * link anyone has ever shared is Bangla, and moving them under `/bn` to make
 * the two symmetrical would be a site-wide 301 for a second edition that most
 * readers will never open.
 *
 * **Two route names for one page.** The `/en` group is registered under an
 * `en.` name prefix, so `category.show` and `en.category.show` are the same
 * page in two editions. `route()` therefore cannot be called directly from a
 * shared template — the header is rendered on both — and `Locale::route()` is
 * the door: it picks the name for the edition the request is in.
 *
 * An article is the exception and does **not** go through that: its URL is
 * fixed by its *own* `locale` column, not by the request's. An English story
 * linked from a Bangla listing must still be an `/en` URL, or the reader gets
 * an English body inside a Bangla frame at a URL that claims to be Bangla.
 * `Article::url()` reads the article; everything else reads the request.
 */
class Locale
{
    public const DEFAULT = 'bn';

    public const ALTERNATE = 'en';

    /** @var list<string> */
    public const ALL = [self::DEFAULT, self::ALTERNATE];

    /**
     * The path segment an edition lives under, `''` for the default one.
     *
     * Reserved as a category slug in `CategoryController`: a section whose
     * path were `en` would be shadowed by this prefix for ever, and the
     * failure is a 404 on a section that exists and looks fine in the admin.
     */
    public static function prefix(string $locale): string
    {
        return $locale === self::DEFAULT ? '' : $locale;
    }

    public static function current(): string
    {
        return in_array(App::getLocale(), self::ALL, true) ? App::getLocale() : self::DEFAULT;
    }

    public static function isDefault(): bool
    {
        return self::current() === self::DEFAULT;
    }

    /** The edition a reader would switch to from this one. */
    public static function other(?string $locale = null): string
    {
        return ($locale ?? self::current()) === self::DEFAULT ? self::ALTERNATE : self::DEFAULT;
    }

    /**
     * A route name in `$locale`'s edition, defaulting to the current one.
     *
     * `en.` is a prefix on the name and not a parameter, so this is a string
     * concatenation rather than a lookup — but going through here rather than
     * writing `'en.'.$name` at each call site means the one place that knows
     * the naming convention is the one place that has to change if it ever
     * stops being a prefix.
     */
    public static function routeName(string $name, ?string $locale = null): string
    {
        $locale ??= self::current();

        return $locale === self::DEFAULT ? $name : $locale.'.'.$name;
    }

    /** `route()`, in the edition the request is in. */
    public static function route(string $name, mixed $parameters = [], ?string $locale = null): string
    {
        return route(self::routeName($name, $locale), $parameters);
    }

    /**
     * The masthead, in the edition's own script.
     *
     * `site.name_en` already existed and was already a romanisation of the
     * Bangla masthead rather than a different publication — it was used in
     * error-alert subject lines and the footer credit, where a Latin-script
     * name is easier to scan. It is the masthead of the English edition now,
     * which is the same paper, so a second config value would be a second
     * thing to keep in step for no editorial reason.
     */
    public static function siteName(?string $locale = null): string
    {
        return ($locale ?? self::current()) === self::DEFAULT
            ? config('site.name_bn')
            : config('site.name_en');
    }

    /** The default meta description, in the edition's own language. */
    public static function siteDescription(?string $locale = null): string
    {
        return ($locale ?? self::current()) === self::DEFAULT
            ? (string) config('site.description', '')
            : (string) config('site.description_en', '');
    }

    /** The human name of an edition, for a switcher that has to label itself. */
    public static function label(string $locale): string
    {
        return match ($locale) {
            self::ALTERNATE => 'English',
            default => 'বাংলা',
        };
    }

    /**
     * The `hreflang` value for an edition.
     *
     * Region-qualified, because these are not generic Bangla and generic
     * English: the edition is written for Bangladesh, and `bn-BD` / `en-BD`
     * is what tells a search engine to prefer it for readers there over a
     * West Bengal or a US edition of the same story.
     */
    public static function hreflang(string $locale): string
    {
        return $locale.'-BD';
    }
}
