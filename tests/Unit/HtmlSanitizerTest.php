<?php

namespace Tests\Unit;

use App\Support\Html;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The allow-list itself, exercised directly.
 *
 * ContentSanitizeTest proves the application calls this on every write. This
 * covers what it decides — the vector table, which is the part that has to be
 * exhaustive and the part that costs nothing to run.
 *
 * No database.
 */
class HtmlSanitizerTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function vectors(): array
    {
        return [
            // --- script execution -------------------------------------------
            'script is removed with its source' => [
                '<p>ভালো</p><script>alert(1)</script>',
                '<p>ভালো</p>',
            ],
            'script source does not resurface as text' => [
                '<script>alert("বিপদ")</script>',
                '',
            ],
            'event handler attribute' => [
                '<p onclick="steal()">লেখা</p>',
                '<p>লেখা</p>',
            ],
            'event handler on an otherwise fine image' => [
                '<img src="/uploads/a.png" onerror="steal()" alt="ছবি">',
                '<img src="/uploads/a.png" alt="ছবি">',
            ],
            'javascript: href' => [
                '<a href="javascript:alert(1)">ক্লিক</a>',
                'ক্লিক',
            ],
            // The parser decodes entities before we see the value, and control
            // characters inside a scheme are stripped, so neither disguise works.
            'entity-encoded javascript: href' => [
                '<a href="&#106;avascript:alert(1)">ক্লিক</a>',
                'ক্লিক',
            ],
            'tab-split javascript: href' => [
                '<a href="java&#09;script:alert(1)">ক্লিক</a>',
                'ক্লিক',
            ],
            'data: URI image' => [
                '<img src="data:image/svg+xml;base64,PHN2Zz48c2NyaXB0Pjwvc2NyaXB0Pjwvc3ZnPg==">',
                '',
            ],
            'conditional comment' => [
                '<!--[if IE]><script>alert(1)</script><![endif]-->ঠিক',
                'ঠিক',
            ],

            // --- foreign content, where HTML4 parsers disagree with browsers --
            'svg is removed whole' => ['<svg onload="alert(1)"></svg>', ''],
            'svg wrapping a script' => ['<svg><script>alert(1)</script></svg>', ''],
            'mglyph mutation vector' => [
                '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>',
                '',
            ],

            // --- things that are simply not prose ---------------------------
            'form controls go, surrounding copy stays' => [
                '<form action="/x"><input name="a"><button>Send</button></form>রয়ে গেল',
                'রয়ে গেল',
            ],
            'style block' => ['<style>body{display:none}</style>বাকি', 'বাকি'],
            'noscript' => ['<noscript><p>nope</p></noscript>ঠিক', 'ঠিক'],
            'textarea hiding markup' => ['<textarea><img src=x onerror=alert(1)></textarea>', ''],

            // --- attributes -------------------------------------------------
            'inline style is dropped so the theme tokens hold' => [
                '<p style="color:#c00">লাল নয়</p>',
                '<p>লাল নয়</p>',
            ],
            'unknown presentational attributes' => [
                '<table><tr><td colspan="2" bgcolor="red">ঘর</td></tr></table>',
                '<table><tbody><tr><td colspan="2">ঘর</td></tr></tbody></table>',
            ],
            'class is filtered to the house tokens' => [
                '<span class="lat evil">Reuters</span>',
                '<span class="lat">Reuters</span>',
            ],
            'target=_blank gains noopener' => [
                '<a href="https://x.example" target="_blank">লিংক</a>',
                '<a href="https://x.example" target="_blank" rel="noopener noreferrer">লিংক</a>',
            ],
            'rel is filtered but nofollow survives' => [
                '<a href="https://x.example" target="_blank" rel="tracking nofollow">লিংক</a>',
                '<a href="https://x.example" target="_blank" rel="nofollow noopener noreferrer">লিংক</a>',
            ],
            'target other than _blank is dropped' => [
                '<a href="https://x.example" target="topFrame">লিংক</a>',
                '<a href="https://x.example">লিংক</a>',
            ],

            // --- embeds -----------------------------------------------------
            'youtube iframe survives' => [
                '<iframe src="https://www.youtube.com/embed/abc" allowfullscreen></iframe>',
                '<iframe src="https://www.youtube.com/embed/abc" allowfullscreen="allowfullscreen"></iframe>',
            ],
            'protocol-relative youtube iframe survives' => [
                '<iframe src="//player.vimeo.com/video/1"></iframe>',
                '<iframe src="//player.vimeo.com/video/1"></iframe>',
            ],
            'iframe from anywhere else is removed' => [
                '<iframe src="https://evil.example/x"></iframe>',
                '',
            ],
            'http embed is refused even from an allowed host' => [
                '<iframe src="http://www.youtube.com/embed/abc"></iframe>',
                '',
            ],

            // --- editorial markup that must survive intact ------------------
            'the toolbar output round-trips' => [
                '<h2>উপশিরোনাম</h2><p><strong>বোল্ড</strong> ও <em>ইটালিক</em></p>'
                    .'<ul><li>এক</li></ul><blockquote><p>উদ্ধৃতি</p></blockquote>',
                '<h2>উপশিরোনাম</h2><p><strong>বোল্ড</strong> ও <em>ইটালিক</em></p>'
                    .'<ul><li>এক</li></ul><blockquote><p>উদ্ধৃতি</p></blockquote>',
            ],
            'figure with a caption' => [
                '<figure><img src="/uploads/2026/08/a.png" alt="ক্যাপশন"><figcaption>ছবি</figcaption></figure>',
                '<figure><img src="/uploads/2026/08/a.png" alt="ক্যাপশন"><figcaption>ছবি</figcaption></figure>',
            ],
            'mailto and tel links' => [
                '<a href="mailto:a@b.example">মেইল</a> <a href="tel:+8801711000000">ফোন</a>',
                '<a href="mailto:a@b.example">মেইল</a> <a href="tel:+8801711000000">ফোন</a>',
            ],
            'a Bangla slug survives in a link and in a heading id' => [
                '<h2 id="ধারা-১">শিরোনাম</h2><p><a href="/অর্থনীতি/১২/রপ্তানি-আয়">খবর</a></p>',
                '<h2 id="ধারা-১">শিরোনাম</h2><p><a href="/অর্থনীতি/১২/রপ্তানি-আয়">খবর</a></p>',
            ],
            'ampersands and Bangla digits are left alone' => [
                '<p>৫০% &amp; আরও <span class="lat">Reuters</span></p>',
                '<p>৫০% &amp; আরও <span class="lat">Reuters</span></p>',
            ],
            'unclosed tags are closed rather than dropped' => [
                '<p>অসমাপ্ত <b>বোল্ড',
                '<p>অসমাপ্ত <b>বোল্ড</b></p>',
            ],
        ];
    }

    #[DataProvider('vectors')]
    public function test_the_allow_list(string $input, string $expected): void
    {
        $this->assertSame($expected, Html::sanitize($input));
    }

    /**
     * The backfill command runs over the whole table and may be run twice.
     * A sanitiser that keeps changing its own output would rewrite every row on
     * every run and never converge.
     */
    #[DataProvider('vectors')]
    public function test_sanitising_is_idempotent(string $input): void
    {
        $once = Html::sanitize($input);

        $this->assertSame($once, Html::sanitize($once));
    }

    public function test_null_and_blank_bodies_are_empty_strings(): void
    {
        $this->assertSame('', Html::sanitize(null));
        $this->assertSame('', Html::sanitize(''));
        $this->assertSame('', Html::sanitize("  \n  "));
    }

    /**
     * Bangla must come back as Bangla, byte for byte.
     *
     * DOMDocument's HTML4 parser hands back numeric entities on some inputs.
     * `body` is covered by the FULLTEXT index, so `&#2476;` where বাংলা used to
     * be would not merely look wrong — it would quietly stop the story being
     * findable.
     */
    public function test_bangla_survives_the_round_trip(): void
    {
        $body = '<p>জলবায়ু পরিবর্তনের প্রভাব মোকাবিলায় ৳১২,৫০০ কোটি টাকা বরাদ্দ।</p>';

        $this->assertSame($body, Html::sanitize($body));
        $this->assertTrue(mb_check_encoding(Html::sanitize($body), 'UTF-8'));
    }

    public function test_the_report_names_what_was_removed(): void
    {
        Html::sanitize('<p onclick="x()">ক</p><script>y()</script><div>খ</div>', $report);

        $this->assertSame(['script' => 1], $report['dropped']);
        $this->assertSame(['div' => 1], $report['unwrapped']);
        $this->assertSame(['p@onclick' => 1], $report['attributes']);
    }

    public function test_is_clean_agrees_with_sanitise(): void
    {
        $this->assertTrue(Html::isClean('<p>ঠিক আছে</p>'));
        $this->assertFalse(Html::isClean('<p>ঠিক</p><script>alert(1)</script>'));
    }
}
