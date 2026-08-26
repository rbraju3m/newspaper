<?php

namespace App\Support;

use Dom\Comment;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\ProcessingInstruction;

/**
 * Allow-list sanitiser for editor-written HTML.
 *
 * Article bodies are stored as HTML and rendered with `{!! !!}`. That was
 * defensible while the only people who could write one were staff; it stops
 * being defensible the moment authorship widens, and it was never defensible
 * against a reporter account that has been phished. Everything that reaches
 * `$article->body` now passes through here first, so what is stored is already
 * safe to print.
 *
 * The parser is PHP 8.4's `Dom\HTMLDocument`, not the old `DOMDocument`. That
 * matters for two reasons:
 *
 *  - It is a real HTML5 parser, so what it builds is what a browser builds.
 *    An HTML4 parser disagrees with the browser about foreign content
 *    (`<svg>`, `<math>`) and about where an unclosed tag ends, and every
 *    mutation-XSS bug in a sanitiser lives in exactly that gap.
 *  - It is UTF-8 native. `DOMDocument` needs the `<meta charset>` incantation
 *    and still hands back numeric entities on some inputs — which would put
 *    `&#2476;` where বাংলা used to be, in a column the FULLTEXT index covers.
 *
 * Anything not named below is removed. Unknown *prose* elements are unwrapped,
 * keeping their text; elements whose contents are not prose (script, style,
 * form controls, foreign content) are removed whole, so a stripped `<script>`
 * cannot leak its source as visible text.
 */
final class Html
{
    /**
     * Element => the attributes it may carry, beyond the global ones.
     *
     * Everything the editor's toolbar emits is here, plus what
     * `.prose-editorial` in `resources/css/app.css` knows how to style. Adding
     * a rung to one without the other gives you markup that survives and
     * renders unstyled.
     */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'hr' => [],
        'h2' => ['id'], 'h3' => ['id'], 'h4' => ['id'],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'sub' => [], 'sup' => [], 'small' => [], 'mark' => [], 'abbr' => ['title'],
        'ul' => [], 'ol' => ['start', 'reversed'], 'li' => ['value'],
        'dl' => [], 'dt' => [], 'dd' => [],
        'blockquote' => ['cite'], 'q' => ['cite'], 'cite' => [],
        'figure' => [], 'figcaption' => [],
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading'],
        'table' => [], 'caption' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [],
        'tr' => [], 'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
        'pre' => [], 'code' => [], 'kbd' => [], 'samp' => [], 'var' => [],
        'time' => ['datetime'],
        'span' => [],   // carries `class="lat"`, the house wrapper for a Latin run
        'wbr' => [],
        'iframe' => ['src', 'width', 'height', 'title', 'allow', 'allowfullscreen', 'frameborder', 'loading', 'referrerpolicy'],
    ];

    /**
     * Allowed on every permitted element. `class` is token-filtered below
     * rather than passed through — an arbitrary class escapes the semantic
     * tokens and will not survive a theme switch.
     *
     * `id` is deliberately *not* here. It is granted to headings only, where it
     * anchors a section link. An id on any element is a DOM-clobbering
     * primitive — `<img id="body">` shadows `document.body` for every script on
     * the page — and headings are the one place the newsroom needs one.
     */
    private const GLOBAL_ATTRIBUTES = ['class', 'dir', 'lang'];

    /**
     * The only classes editorial copy has any business setting. `lat` is the
     * house convention for a Latin run inside Bangla text; `tabular` is its
     * numerals-only sibling. Both are defined in `app.css`.
     */
    private const ALLOWED_CLASSES = ['lat', 'tabular'];

    /**
     * Removed with everything inside them. Unwrapping these would turn script
     * source, stylesheet text or a form's labels into visible copy.
     */
    private const DROPPED = [
        'script', 'style', 'noscript', 'template', 'svg', 'math',
        'object', 'embed', 'param', 'applet', 'canvas',
        'audio', 'video', 'source', 'track',
        'form', 'input', 'button', 'select', 'option', 'optgroup', 'textarea',
        'link', 'meta', 'base', 'title', 'head',
        'frame', 'frameset', 'portal', 'dialog', 'slot',
        'xmp', 'plaintext', 'listing',
    ];

    /**
     * Written without a value (`<iframe allowfullscreen>`). An empty string
     * is what they mean, so the usual "drop anything empty" rule would eat
     * them; they are echoed back as `name="name"` instead, which is valid
     * and survives a second pass unchanged.
     */
    private const BOOLEAN_ATTRIBUTES = ['allowfullscreen', 'reversed'];

    /** Attributes carrying a URL, checked by {@see safeUrl()}. */
    private const URL_ATTRIBUTES = ['href', 'src', 'cite'];

    /** Schemes a link may use. Notably absent: `data:` and `javascript:`. */
    private const SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Hosts an `<iframe>` may point at. An iframe is the one allowed element
     * that executes code, so it is allow-listed by host rather than by scheme.
     * Extend this when the newsroom starts embedding somewhere new.
     */
    private const EMBED_HOSTS = [
        'www.youtube.com', 'youtube.com',
        'www.youtube-nocookie.com', 'youtube-nocookie.com',
        'player.vimeo.com',
        'www.facebook.com',
        'www.dailymotion.com', 'geo.dailymotion.com',
        'open.spotify.com',
        'www.instagram.com',
    ];

    /** `rel` tokens worth keeping; the rest are noise or tracking hints. */
    private const REL_TOKENS = ['noopener', 'noreferrer', 'nofollow', 'ugc', 'sponsored'];

    /**
     * Clean a fragment of editor HTML.
     *
     * `$report` comes back counting what was taken out, keyed by kind:
     * `['dropped' => ['script' => 1], 'unwrapped' => [...], 'attributes' => [...]]`.
     * `content:sanitize` prints it; the tests assert on it.
     */
    public static function sanitize(?string $html, ?array &$report = null): string
    {
        $report = ['dropped' => [], 'unwrapped' => [], 'attributes' => []];

        $html = (string) $html;

        if (trim($html) === '') {
            return '';
        }

        // createFromString() on a bare fragment still builds html/head/body, so
        // the wrapper is only here to keep leading text out of <head>.
        $document = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body>'.$html.'</body></html>',
            LIBXML_NOERROR,
        );

        $body = $document->getElementsByTagName('body')->item(0);

        if (! $body instanceof Element) {
            return '';
        }

        self::cleanChildren($body, $report);

        $out = '';

        foreach ($body->childNodes as $node) {
            $out .= $document->saveHtml($node);
        }

        return trim($out);
    }

    /**
     * True when sanitising would change nothing — the body is already clean
     * *and* already normalised. A body that only differs by an unclosed `<p>`
     * counts as dirty; that is deliberate, since normalising is what makes the
     * check idempotent on the next run.
     */
    public static function isClean(?string $html): bool
    {
        return self::sanitize($html) === trim((string) $html);
    }

    private static function cleanChildren(Node $parent, array &$report): void
    {
        // Snapshot: the loop removes and moves nodes out from under itself.
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof Comment || $child instanceof ProcessingInstruction) {
                // `<!--[if IE]><script>…<![endif]-->` is a comment to the parser
                // and markup to the browser that reads it.
                $child->remove();

                continue;
            }

            if (! $child instanceof Element) {
                continue;   // text and CDATA pass through, escaped on output
            }

            $tag = strtolower($child->localName);

            if (in_array($tag, self::DROPPED, true)) {
                self::count($report, 'dropped', $tag);
                $child->remove();

                continue;
            }

            if ($tag === 'iframe' && ! self::isAllowedEmbed($child)) {
                self::count($report, 'dropped', 'iframe');
                $child->remove();

                continue;
            }

            if (! array_key_exists($tag, self::ALLOWED)) {
                self::cleanChildren($child, $report);
                self::count($report, 'unwrapped', $tag);
                self::unwrap($child);

                continue;
            }

            self::cleanAttributes($child, $tag, $report);

            // An <img> whose src was refused is an empty box on the page.
            if ($tag === 'img' && ! $child->hasAttribute('src')) {
                self::count($report, 'dropped', 'img');
                $child->remove();

                continue;
            }

            self::cleanChildren($child, $report);

            // An <a> whose href was refused is a dead link wrapped around live
            // copy. Keep the words, lose the anchor.
            if ($tag === 'a' && ! $child->hasAttribute('href')) {
                self::count($report, 'unwrapped', 'a');
                self::unwrap($child);
            }
        }
    }

    /** Replace an element with its children, keeping the text it wrapped. */
    private static function unwrap(Element $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $element->remove();
    }

    private static function cleanAttributes(Element $element, string $tag, array &$report): void
    {
        $allowed = array_merge(self::ALLOWED[$tag], self::GLOBAL_ATTRIBUTES);

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            $value = trim($attribute->value);

            // `on*` is covered by the allow-list; naming it makes the report
            // legible when a handler is what got stripped.
            if (str_starts_with($name, 'on') || ! in_array($name, $allowed, true)) {
                self::count($report, 'attributes', $tag.'@'.$name);
                $element->removeAttribute($attribute->name);

                continue;
            }

            $clean = match (true) {
                in_array($name, self::BOOLEAN_ATTRIBUTES, true) => $name,
                in_array($name, self::URL_ATTRIBUTES, true) => self::safeUrl($value),
                $name === 'class' => self::filterTokens($value, self::ALLOWED_CLASSES),
                $name === 'rel' => self::filterTokens($value, self::REL_TOKENS),
                $name === 'id' => preg_match('/^[\p{L}\p{M}\p{N}_][\p{L}\p{M}\p{N}_:.-]*$/u', $value) ? $value : null,
                $name === 'dir' => in_array(strtolower($value), ['ltr', 'rtl', 'auto'], true) ? strtolower($value) : null,
                $name === 'lang' => preg_match('/^[A-Za-z]{2,8}(-[A-Za-z0-9]{1,8})*$/', $value) ? $value : null,
                $name === 'target' => $value === '_blank' ? '_blank' : null,
                default => $value === '' ? null : $value,
            };

            if ($clean === null || $clean === '') {
                self::count($report, 'attributes', $tag.'@'.$name);
                $element->removeAttribute($attribute->name);

                continue;
            }

            if ($clean !== $attribute->value) {
                $element->setAttribute($attribute->name, $clean);
            }
        }

        // A `target="_blank"` without `rel` hands the opened tab a live
        // `window.opener` back into the article. Browsers imply noopener now;
        // older ones and in-app webviews do not.
        if ($tag === 'a' && $element->getAttribute('target') === '_blank') {
            $rel = self::filterTokens($element->getAttribute('rel').' noopener noreferrer', self::REL_TOKENS);
            $element->setAttribute('rel', (string) $rel);
        }
    }

    /**
     * An iframe survives only if it points at a known embed host over https.
     */
    private static function isAllowedEmbed(Element $element): bool
    {
        $src = self::safeUrl(trim($element->getAttribute('src')));

        if ($src === null) {
            return false;
        }

        // A protocol-relative embed inherits the page's https.
        $host = parse_url(str_starts_with($src, '//') ? 'https:'.$src : $src, PHP_URL_HOST);
        $scheme = parse_url(str_starts_with($src, '//') ? 'https:'.$src : $src, PHP_URL_SCHEME);

        if (! is_string($host) || $scheme !== 'https') {
            return false;
        }

        return in_array(strtolower($host), self::EMBED_HOSTS, true);
    }

    /**
     * Null for anything a browser would treat as script.
     *
     * The parser has already decoded entities, so `&#106;avascript:` arrives
     * here spelled out. What it does *not* do is strip the control characters
     * browsers ignore inside a scheme — `java\tscript:` runs — so that is the
     * first thing to go.
     */
    private static function safeUrl(string $value): ?string
    {
        $url = trim(preg_replace('/[\x00-\x1F\x7F]+/', '', $value) ?? '');

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '#') || str_starts_with($url, '/')) {
            return $url;   // fragment, root-relative, or protocol-relative
        }

        if (preg_match('#^([A-Za-z][A-Za-z0-9+.-]*):#', $url, $matches)) {
            return in_array(strtolower($matches[1]), self::SCHEMES, true) ? $url : null;
        }

        return $url;   // a relative path
    }

    /** Keep only the listed tokens, deduplicated, order preserved. */
    private static function filterTokens(string $value, array $allowed): ?string
    {
        $tokens = array_values(array_unique(array_filter(
            array_map('strtolower', preg_split('/\s+/', trim($value)) ?: []),
            fn (string $token) => in_array($token, $allowed, true),
        )));

        return $tokens === [] ? null : implode(' ', $tokens);
    }

    private static function count(array &$report, string $kind, string $key): void
    {
        $report[$kind][$key] = ($report[$kind][$key] ?? 0) + 1;
    }
}
