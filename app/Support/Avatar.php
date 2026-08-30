<?php

namespace App\Support;

/**
 * The fallback avatar for a reader who has not uploaded one, drawn locally.
 *
 * It used to be `ui-avatars.com`. That was one third-party request per face,
 * and a comment thread or an author page is a page full of faces — so every
 * reader's IP address and the article they were reading went to a service
 * nobody chose, on a site whose imagery is otherwise all local and all drawn.
 * It was also a point of failure for something purely decorative.
 *
 * **An SVG data URI rather than a drawn PNG, and that is the whole reason
 * this is not another `SeedImagery`.** GD does no complex shaping, which is
 * why `brand:icons` and `EpaperSeeder` are wordless — Bangla conjuncts and
 * vowel signs come out unformed. An SVG is shaped by the *browser*, so
 * `বার্তা সম্পাদক` gets a legible `বস` at every size. Checked in Chrome at
 * 88, 40 and 32px, which are the three sizes the site asks for.
 *
 * Two more things it buys, neither of them the point but both real:
 *
 * - **No request at all**, where the old one was a request per distinct name
 *   per page.
 * - **It is vector.** `ui-avatars` returned a 64×64 PNG and the author page
 *   renders at 88, so that avatar was being upscaled.
 *
 * The cost is ~460 bytes of markup per face, inline and therefore not
 * cacheable across pages. On the heaviest page that uses it — a comment
 * thread — that is a few KB of extremely repetitive text, which gzip removes
 * almost entirely.
 */
class Avatar
{
    /** Brand red, matching `--color-brand` in `resources/css/app.css`. */
    private const BRAND = '#C8102E';

    /**
     * A square SVG of the initials on the brand colour, as a data URI.
     *
     * Every template rounds it off with `rounded-full`, so this stays square
     * and lets the CSS decide — the same box the uploaded photograph gets.
     *
     * **`rawurlencode`, not a targeted escape of `<` and `#`.** It leaves
     * only `A-Za-z0-9-_.~` and `%`, so the result cannot carry a quote, a
     * space or an angle bracket into whatever attribute it is printed in —
     * including `account/index.blade.php`, where it sits inside a
     * single-quoted Alpine expression. The extra bytes buy that.
     */
    public static function dataUri(string $initials): string
    {
        // Escaped as XML as well, so a name holding `&` or `<` produces a
        // valid document rather than an image that silently fails to render.
        $text = htmlspecialchars($initials, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            .'<rect width="100" height="100" fill="'.self::BRAND.'"/>'
            .'<text x="50" y="50" fill="#fff" font-family="sans-serif" font-size="42"'
            .' font-weight="700" text-anchor="middle" dominant-baseline="central">'
            .$text
            .'</text></svg>';

        return 'data:image/svg+xml;charset=UTF-8,'.rawurlencode($svg);
    }
}
