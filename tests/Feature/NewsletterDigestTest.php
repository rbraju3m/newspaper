<?php

namespace Tests\Feature;

use App\Mail\NewsletterDigest;
use App\Models\Article;
use App\Models\Category;
use App\Models\NewsletterSubscriber;
use App\Services\NewsletterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * `newsletter:send` — the sending side, which was the standing gap.
 *
 * `Mail::fake()` in setUp and never anywhere else: `.env` on the development
 * box points MAIL_MAILER at a live Gmail account, so a test that reached the
 * real mailer would send real mail from a real address.
 */
class NewsletterDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function subscriber(array $attributes = []): NewsletterSubscriber
    {
        // array_merge, not `+`: the union operator keeps the LEFT operand on a
        // key collision, so `+ $attributes` silently discards every override.
        $subscriber = NewsletterSubscriber::create(array_merge([
            'email' => fake()->unique()->userName().'@gmail.com',
            'name' => 'রফিক',
        ], $attributes));

        $subscriber->forceFill(['verified_at' => now()->subWeek()])->save();

        return $subscriber->fresh();
    }

    private function article(array $attributes = []): Article
    {
        return Article::factory()
            ->for(Category::factory(), 'category')
            ->create(array_merge([
                'status' => \App\Enums\ArticleStatus::Published,
                'published_at' => now()->subHours(3),
            ], $attributes));
    }

    // ── Who receives it ──────────────────────────────────────────────────

    public function test_it_sends_to_a_verified_subscriber(): void
    {
        $subscriber = $this->subscriber();
        $this->article();

        $this->artisan('newsletter:send')->assertSuccessful();

        Mail::assertSent(NewsletterDigest::class,
            fn (NewsletterDigest $mail) => $mail->hasTo($subscriber->email));

        $this->assertNotNull($subscriber->fresh()->last_sent_at);
    }

    /**
     * Double opt-in is only worth having if the send list honours it.
     */
    public function test_an_unverified_address_is_never_mailed(): void
    {
        NewsletterSubscriber::create(['email' => 'never@gmail.com']);
        $this->article();

        $this->artisan('newsletter:send')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_an_unsubscribed_reader_is_never_mailed(): void
    {
        $this->subscriber()->forceFill(['unsubscribed_at' => now()])->save();
        $this->article();

        $this->artisan('newsletter:send')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_the_weekly_list_and_the_daily_list_are_different_people(): void
    {
        $daily = $this->subscriber(['frequency' => 'daily']);
        $weekly = $this->subscriber(['frequency' => 'weekly']);
        $this->article();

        $this->artisan('newsletter:send --frequency=daily')->assertSuccessful();

        Mail::assertSent(NewsletterDigest::class, fn ($mail) => $mail->hasTo($daily->email));
        Mail::assertNotSent(NewsletterDigest::class, fn ($mail) => $mail->hasTo($weekly->email));
    }

    /** The guard against a second cron run mailing the same digest twice. */
    public function test_a_second_run_does_not_send_again(): void
    {
        $this->subscriber();
        $this->article();

        $this->artisan('newsletter:send')->assertSuccessful();
        $this->artisan('newsletter:send')->assertSuccessful();

        Mail::assertSentCount(1);
    }

    public function test_yesterdays_send_does_not_block_todays(): void
    {
        $this->subscriber()->forceFill(['last_sent_at' => now()->subDays(2)])->save();
        $this->article();

        $this->artisan('newsletter:send')->assertSuccessful();

        Mail::assertSentCount(1);
    }

    // ── What it contains ─────────────────────────────────────────────────

    /**
     * A newsletter that arrives every morning whether or not anything happened
     * is a newsletter that gets filtered — and after that, none of them arrive.
     */
    public function test_a_quiet_news_day_sends_nothing(): void
    {
        $subscriber = $this->subscriber();
        $this->article(['published_at' => now()->subDays(5)]);   // outside the daily window

        $this->artisan('newsletter:send')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertNull($subscriber->fresh()->last_sent_at,
            'Skipped for want of stories is not sent — they are still due tomorrow.');
    }

    public function test_a_reader_who_follows_sections_gets_only_those(): void
    {
        $sport = Category::factory()->create();
        $politics = Category::factory()->create();

        $wanted = $this->article(['category_id' => $sport->id]);
        $unwanted = $this->article(['category_id' => $politics->id]);

        $subscriber = $this->subscriber(['categories' => [$sport->id]]);

        $this->artisan('newsletter:send')->assertSuccessful();

        Mail::assertSent(NewsletterDigest::class, function (NewsletterDigest $mail) use ($wanted, $unwanted) {
            $ids = $mail->articles->pluck('id');

            return $ids->contains($wanted->id) && ! $ids->contains($unwanted->id);
        });
    }

    /** A section that published nothing leaves that reader with no edition. */
    public function test_a_follower_of_a_quiet_section_is_skipped(): void
    {
        $quiet = Category::factory()->create();
        $this->article();                      // in some other section

        $this->subscriber(['categories' => [$quiet->id]]);

        $this->artisan('newsletter:send')->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Editorial before algorithmic. A digest ordered purely by views leads on
     * whatever went viral, which is not the same as whatever mattered.
     */
    public function test_the_desks_lead_leads_the_email(): void
    {
        $viral = $this->article(['views' => 90_000, 'is_lead' => false, 'is_featured' => false]);
        $lead = $this->article(['views' => 12, 'is_lead' => true]);

        $this->subscriber();

        $this->artisan('newsletter:send')->assertSuccessful();

        Mail::assertSent(NewsletterDigest::class, function (NewsletterDigest $mail) use ($lead, $viral) {
            return $mail->articles->first()->id === $lead->id
                && $mail->articles->pluck('id')->contains($viral->id);
        });
    }

    public function test_the_subject_names_the_lead_story(): void
    {
        $lead = $this->article(['is_lead' => true, 'title' => 'সংসদে বাজেট পাস']);
        $this->subscriber();

        $this->artisan('newsletter:send')->assertSuccessful();

        Mail::assertSent(NewsletterDigest::class,
            fn (NewsletterDigest $mail) => str_contains($mail->subjectLine, 'সংসদে বাজেট পাস'));
    }

    // ── Deliverability ───────────────────────────────────────────────────

    /**
     * One-click unsubscribe. Without these headers a bulk sender gets throttled
     * on reputation, and a reader who cannot find the link presses "spam"
     * instead — the one signal there is no recovering from.
     */
    public function test_every_edition_carries_one_click_unsubscribe_headers(): void
    {
        $subscriber = $this->subscriber();
        $this->article();

        $this->artisan('newsletter:send')->assertSuccessful();

        Mail::assertSent(NewsletterDigest::class, function (NewsletterDigest $mail) use ($subscriber) {
            $headers = $mail->headers()->text;

            // Must name the POST route: the mail client calls it directly and
            // renders nothing, so a confirmation page would leave the reader
            // still subscribed and sure they had unsubscribed.
            return $headers['List-Unsubscribe']
                    === '<'.route('newsletter.unsubscribe.click', $subscriber->token).'>'
                && $headers['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click'
                && isset($headers['List-ID']);
        });
    }

    public function test_the_body_carries_a_visible_unsubscribe_link(): void
    {
        $subscriber = $this->subscriber();
        $this->article();

        $this->artisan('newsletter:send')->assertSuccessful();

        Mail::assertSent(NewsletterDigest::class, function (NewsletterDigest $mail) use ($subscriber) {
            $rendered = $mail->render();

            return str_contains($rendered, $subscriber->unsubscribeUrl())
                && str_contains($rendered, 'নিউজলেটার বন্ধ করুন');
        });
    }

    /** Both halves must render — a text part is what a filter reads first. */
    public function test_it_renders_html_and_plain_text(): void
    {
        $article = $this->article(['title' => 'নদীভাঙন রোধে নতুন প্রকল্প']);
        $subscriber = $this->subscriber();

        $mail = new NewsletterDigest(
            $subscriber,
            app(NewsletterService::class)->editionFor($subscriber, 'daily'),
            'daily',
            'আজকের খবর',
        );

        $html = $mail->render();

        $this->assertStringContainsString($article->title, $html);
        $this->assertStringContainsString($article->url, $html);
        $this->assertNotNull($mail->content()->text, 'A mail with no text part reads as spam to a filter.');
    }

    // ── The command ──────────────────────────────────────────────────────

    public function test_a_dry_run_sends_nothing_and_stamps_nothing(): void
    {
        $subscriber = $this->subscriber();
        $this->article();

        $this->artisan('newsletter:send --dry-run')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertNull($subscriber->fresh()->last_sent_at);
    }

    public function test_an_unknown_frequency_is_refused(): void
    {
        $this->artisan('newsletter:send --frequency=hourly')->assertFailed();

        Mail::assertNothingSent();
    }

    /** `--to` is for checking the template, and still honours verification. */
    public function test_to_targets_one_address_and_still_refuses_the_unverified(): void
    {
        $verified = $this->subscriber();
        $unverified = NewsletterSubscriber::create(['email' => 'nope@gmail.com']);
        $this->article();

        $this->artisan('newsletter:send --to='.$unverified->email)->assertSuccessful();
        Mail::assertNothingSent();

        $this->artisan('newsletter:send --to='.$verified->email)->assertSuccessful();
        Mail::assertSentCount(1);
    }

    /** One rejected address must not cost the other 3,999 readers their edition. */
    public function test_one_failing_address_does_not_end_the_run(): void
    {
        $first = $this->subscriber(['email' => 'aaa@gmail.com']);
        $second = $this->subscriber(['email' => 'bbb@gmail.com']);
        $this->article();

        Mail::shouldReceive('to')->andReturnUsing(function (string $address) use ($first) {
            if ($address === $first->email) {
                throw new \RuntimeException('550 mailbox unavailable');
            }

            return new class
            {
                public function send($mailable): void {}
            };
        });

        $this->artisan('newsletter:send')->assertSuccessful();

        $this->assertNull($first->fresh()->last_sent_at, 'A failure must not be stamped as sent.');
        $this->assertNotNull($second->fresh()->last_sent_at, 'The rest of the list still went out.');
    }
}
