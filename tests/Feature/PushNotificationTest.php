<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Enums\UserRole;
use App\Models\Article;
use App\Models\Category;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Client\ClientInterface;
use Tests\TestCase;

/**
 * Web Push, end to end: subscribing, the account switch, sending, and the
 * pruning that keeps a sender from being blocked.
 *
 * Nothing here reaches the network. `PushService` takes its PSR-18 client from
 * the container when one is bound, and every test that sends binds a Guzzle
 * mock in front of it — a test that sent for real would be a test that posts
 * to Google, which is not a test.
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'push.public_key' => 'BMdM-EuSb3l3IVi7Z2XYlnyyEJCcxWTdjzNizmlFC0Wm6gh3YMhjsfuzYMKY_FSIGrQoVmRZW0gBec_srwDHdSc',
            'push.private_key' => '3hXc-_2d-XwAldoNaFillhYmO5CBlIJNGhYML-Ipb5c',
            'push.subject' => 'mailto:desk@example.com',
        ]);
    }

    /** Queues one response per subscription, in the order they will be sent. */
    private function fakePushService(Response ...$responses): MockHandler
    {
        $handler = new MockHandler($responses);

        $this->app->instance(ClientInterface::class, new Client([
            'handler' => HandlerStack::create($handler),
        ]));

        return $handler;
    }

    private function payload(?string $endpoint = null): array
    {
        return [
            'endpoint' => $endpoint ?? 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => [
                'p256dh' => base64_encode(random_bytes(65)),
                'auth' => base64_encode(random_bytes(16)),
            ],
            'contentEncoding' => 'aes128gcm',
        ];
    }

    // ── Subscribing ──────────────────────────────────────────────────────

    /** Most readers of a news site are not signed in. They are the audience. */
    public function test_a_guest_may_subscribe(): void
    {
        $this->postJson(route('push.subscribe'), $this->payload())->assertOk();

        $subscription = PushSubscription::sole();

        $this->assertNull($subscription->user_id);
        $this->assertTrue($subscription->breaking);
        $this->assertSame('aes128gcm', $subscription->content_encoding);
    }

    public function test_a_signed_in_reader_gets_their_subscription_labelled(): void
    {
        $user = User::factory()->create()->fresh();

        $this->actingAs($user)->postJson(route('push.subscribe'), $this->payload())->assertOk();

        $this->assertSame($user->id, PushSubscription::sole()->user_id);
    }

    /** The endpoint is the identity, so the same browser is one row for ever. */
    public function test_re_subscribing_the_same_browser_updates_one_row(): void
    {
        $first = $this->payload();

        $this->postJson(route('push.subscribe'), $first)->assertOk();

        $second = $first;
        $second['keys']['auth'] = base64_encode(random_bytes(16));

        $this->postJson(route('push.subscribe'), $second)->assertOk();

        $this->assertSame(1, PushSubscription::count());
        $this->assertSame($second['keys']['auth'], PushSubscription::sole()->auth_token);
    }

    public function test_a_subscription_without_its_keys_is_refused(): void
    {
        $this->postJson(route('push.subscribe'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_unsubscribing_removes_the_row(): void
    {
        $subscription = PushSubscription::factory()->create();

        $this->deleteJson(route('push.unsubscribe'), ['endpoint' => $subscription->endpoint])->assertOk();

        $this->assertSame(0, PushSubscription::count());
    }

    /**
     * The browser has already dropped its subscription by the time this
     * arrives, so the endpoint is gone either way. A 404 would only give the
     * page an error to report about something that is exactly as asked.
     */
    public function test_unsubscribing_something_that_was_never_there_still_succeeds(): void
    {
        $this->deleteJson(route('push.unsubscribe'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/gone'])
            ->assertOk();
    }

    /** Off rather than broken: a deployment with no keys refuses politely. */
    public function test_subscribing_is_refused_when_push_is_not_configured(): void
    {
        config(['push.public_key' => null, 'push.private_key' => null]);

        $this->postJson(route('push.subscribe'), $this->payload())->assertStatus(503);

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_the_key_is_in_the_page_only_when_it_exists(): void
    {
        $this->get('/')->assertOk()->assertSee('name="push-key"', false);

        config(['push.public_key' => null, 'push.private_key' => null]);

        $this->get('/')->assertOk()->assertDontSee('name="push-key"', false);
    }

    // ── The account switch ───────────────────────────────────────────────

    /**
     * The account preference is the half a server can act on. It cannot create
     * a subscription — only the browser can grant that — but it can stand every
     * one of this reader's browsers down, and before this it did nothing at all.
     */
    public function test_turning_breaking_alerts_off_silences_that_readers_browsers(): void
    {
        $user = User::factory()->create()->fresh();
        $mine = PushSubscription::factory()->for($user)->create();
        $somebodyElses = PushSubscription::factory()->create();

        $this->actingAs($user)->patch(route('account.preferences.update'), [
            'newsletter_frequency' => 'daily',
        ])->assertRedirect();

        $this->assertFalse($mine->refresh()->breaking);
        $this->assertTrue($somebodyElses->refresh()->breaking, 'Only this reader’s own browsers.');

        $this->actingAs($user)->patch(route('account.preferences.update'), [
            'breaking_alerts' => '1',
            'newsletter_frequency' => 'daily',
        ])->assertRedirect();

        $this->assertTrue($mine->refresh()->breaking);
    }

    public function test_a_silenced_subscription_is_not_in_the_audience(): void
    {
        PushSubscription::factory()->count(2)->create();
        PushSubscription::factory()->silenced()->create();

        $this->assertSame(2, PushSubscription::query()->forBreaking()->count());
    }

    // ── Sending ──────────────────────────────────────────────────────────

    private function publishedArticle(): Article
    {
        return Article::factory()
            ->for(Category::factory(), 'category')
            ->create(['status' => ArticleStatus::Published, 'published_at' => now()->subHour()]);
    }

    public function test_the_payload_matches_the_contract_the_service_worker_reads(): void
    {
        $article = $this->publishedArticle();

        $payload = app(PushService::class)->payloadFor($article);

        // These five keys are read by name in public/sw.js. Renaming one here
        // shows the fallback title on every device that has not updated.
        $this->assertSame(['title', 'body', 'url', 'icon', 'tag'], array_keys($payload));
        $this->assertSame($article->title, $payload['title']);
        $this->assertSame($article->url, $payload['url']);
        $this->assertSame('article-'.$article->id, $payload['tag']);
        $this->assertLessThanOrEqual(140, mb_strlen($payload['body']),
            'A lock screen truncates anyway; the cap is so the useful half survives it.');
    }

    public function test_it_sends_to_every_live_subscription(): void
    {
        PushSubscription::factory()->count(3)->create();
        PushSubscription::factory()->silenced()->create();

        $this->fakePushService(
            new Response(201), new Response(201), new Response(201),
        );

        $result = app(PushService::class)->send(['title' => 'ভূমিকম্প', 'body' => '', 'url' => '/']);

        $this->assertSame(3, $result->sent, 'The silenced subscription is not part of the audience.');
        $this->assertSame(0, $result->failed);
        $this->assertNotNull(PushSubscription::query()->forBreaking()->first()->last_success_at);
    }

    /**
     * A push service answering 410 is saying the browser is gone. Continuing to
     * send to it is what gets a sender rate-limited, so the row goes.
     */
    public function test_a_subscription_the_push_service_says_is_gone_is_deleted(): void
    {
        $live = PushSubscription::factory()->create(['endpoint' => 'https://fcm.googleapis.com/fcm/send/aaa']);
        $dead = PushSubscription::factory()->create(['endpoint' => 'https://fcm.googleapis.com/fcm/send/bbb']);

        $this->fakePushService(new Response(201), new Response(410));

        $result = app(PushService::class)->send(['title' => 'x', 'body' => '', 'url' => '/']);

        $this->assertSame(1, $result->sent);
        $this->assertSame(1, $result->pruned);
        $this->assertSame(0, $result->failed, 'A gone browser is the system working, not a failure.');

        $this->assertModelExists($live);
        $this->assertModelMissing($dead);
    }

    /** A real failure is counted as one, and the row is kept for the retry. */
    public function test_a_server_error_is_a_failure_and_keeps_the_subscription(): void
    {
        $subscription = PushSubscription::factory()->create();

        $this->fakePushService(new Response(500));

        $result = app(PushService::class)->send(['title' => 'x', 'body' => '', 'url' => '/']);

        $this->assertSame(0, $result->sent);
        $this->assertSame(1, $result->failed);
        $this->assertModelExists($subscription);
        $this->assertNull($subscription->refresh()->last_success_at);
    }

    public function test_sending_without_keys_reports_rather_than_pretends(): void
    {
        config(['push.public_key' => null, 'push.private_key' => null]);
        PushSubscription::factory()->create();

        $result = app(PushService::class)->send(['title' => 'x', 'body' => '', 'url' => '/']);

        $this->assertSame(0, $result->total());
        $this->assertContains('push is not configured', $result->reasons);
    }

    // ── The command ──────────────────────────────────────────────────────

    public function test_the_command_sends_and_stamps_the_article(): void
    {
        $article = $this->publishedArticle();
        PushSubscription::factory()->create();

        $this->fakePushService(new Response(201));

        $this->artisan('push:send', ['article' => $article->id])
            ->expectsConfirmation('Send to 1 subscription(s)? This cannot be undone.', 'yes')
            ->assertSuccessful();

        $this->assertNotNull($article->refresh()->push_sent_at);
    }

    /** A push cannot be recalled, so the guard is in the database. */
    public function test_the_command_refuses_to_send_the_same_article_twice(): void
    {
        $article = $this->publishedArticle();
        $article->forceFill(['push_sent_at' => now()->subMinutes(5)])->save();
        PushSubscription::factory()->create();

        $this->artisan('push:send', ['article' => $article->id])->assertFailed();
    }

    /** A notification is a link, and a draft is a link to a 404. */
    public function test_the_command_refuses_an_unpublished_article(): void
    {
        $article = Article::factory()
            ->for(Category::factory(), 'category')
            ->create(['status' => ArticleStatus::Draft, 'published_at' => null]);

        $this->artisan('push:send', ['article' => $article->id])->assertFailed();

        $this->assertNull($article->refresh()->push_sent_at);
    }

    public function test_a_dry_run_sends_nothing_and_stamps_nothing(): void
    {
        $article = $this->publishedArticle();
        PushSubscription::factory()->create();

        // No client is bound: if the dry run tried to send, it would reach for
        // the network and this test would not pass quietly.
        $this->artisan('push:send', ['article' => $article->id, '--dry-run' => true])->assertSuccessful();

        $this->assertNull($article->refresh()->push_sent_at);
    }

    public function test_the_command_says_so_when_push_is_not_configured(): void
    {
        config(['push.public_key' => null, 'push.private_key' => null]);

        $this->artisan('push:send', ['article' => $this->publishedArticle()->id])->assertFailed();
    }

    public function test_push_keys_refuses_to_overwrite_a_configured_pair(): void
    {
        $this->artisan('push:keys')->assertFailed();
        $this->artisan('push:keys', ['--force' => true])->assertSuccessful();
    }

    // ── The admin button ─────────────────────────────────────────────────

    public function test_an_editor_may_send_the_alert(): void
    {
        $article = $this->publishedArticle();
        PushSubscription::factory()->create();

        $this->fakePushService(new Response(201));

        $editor = User::factory()->create(['role' => UserRole::Editor])->fresh();

        $this->actingAs($editor)
            ->post(route('admin.articles.push', $article))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertNotNull($article->refresh()->push_sent_at);
    }

    /**
     * Reaching every reader on the site is at least as consequential as putting
     * the story on it, and a reporter may do neither.
     */
    public function test_a_reporter_may_not_send_the_alert(): void
    {
        $article = $this->publishedArticle();
        PushSubscription::factory()->create();

        $reporter = User::factory()->create(['role' => UserRole::Reporter])->fresh();

        $this->actingAs($reporter)
            ->post(route('admin.articles.push', $article))
            ->assertForbidden();

        $this->assertNull($article->refresh()->push_sent_at);
    }

    public function test_a_guest_may_not_send_the_alert(): void
    {
        $article = $this->publishedArticle();

        $this->post(route('admin.articles.push', $article))->assertRedirect(route('login'));

        $this->assertNull($article->refresh()->push_sent_at);
    }

    /**
     * A subscription made while testing is a real browser. Left behind, it
     * receives the first genuine breaking alert after launch.
     */
    public function test_demo_purge_takes_the_subscriptions_with_it(): void
    {
        // The purge refuses to leave nobody who can sign in, so it needs
        // somebody to keep before it will do anything at all.
        User::factory()->create(['role' => UserRole::Admin]);
        PushSubscription::factory()->count(3)->create();

        $this->artisan('demo:purge --force')->assertSuccessful();

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_the_admin_button_refuses_a_second_send(): void
    {
        $article = $this->publishedArticle();
        $article->forceFill(['push_sent_at' => now()->subMinute()])->save();

        $editor = User::factory()->create(['role' => UserRole::Editor])->fresh();

        $this->actingAs($editor)
            ->post(route('admin.articles.push', $article))
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
