<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AdService;
use App\Support\Bangla;
use App\View\Composers\AdminComposer;
use App\View\Composers\LayoutComposer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request so a page with six ad slots issues one query.
        $this->app->singleton(AdService::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! app()->isProduction());
        Model::unguard(false);

        if (app()->isProduction()) {
            URL::forceScheme('https');
        }

        $this->registerBladeDirectives();
        $this->registerRateLimiters();
        $this->localiseAuthNotifications();

        // Site-wide configuration: ads, static pages, users, settings.
        // Every admin controller that touches these must authorise against it —
        // hiding the sidebar link is presentation, not access control.
        Gate::define('manage-site', fn (User $user) => $user->role->canManageSite());

        // Editorial abilities used by the admin nav and screens.
        Gate::define('manage-taxonomy', fn (User $user) => $user->role->canPublish());

        // Header, mega menu, search overlay and footer all need the taxonomy.
        View::composer(
            ['partials.header', 'partials.mega-menu', 'partials.search-overlay', 'partials.footer'],
            LayoutComposer::class,
        );

        View::composer('layouts.admin', AdminComposer::class);
    }

    /**
     * Named rate limiters, applied as `throttle:<name>` in the route files.
     *
     * Two rules shape the numbers. Authenticated traffic is keyed by **user
     * id**, not IP: a newsroom sits behind one NAT, and an IP bucket would
     * have the whole desk sharing one editor's allowance. Unauthenticated
     * traffic has nothing else to key on, so it is keyed by IP and the limits
     * are set where no real reader could reach them.
     *
     * These are a backstop, not the whole defence. A limit that a user can
     * hit by using the site normally is a bug, so anything that needs a tight
     * limit gets it in the controller where it can explain itself in Bangla —
     * `CommentController` refuses a second comment within a minute that way,
     * and this only stops the request that never should have arrived.
     */
    private function registerRateLimiters(): void
    {
        $byUserOrIp = fn (Request $request) => $request->user()?->id ?: $request->ip();

        // ── Unauthenticated writes ───────────────────────────────────────
        // Subscribing is a once-ever action. Five an hour is already absurd,
        // and the endpoint does a blocking DNS lookup per attempt.
        RateLimiter::for('newsletter', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        // A guest's vote is fingerprinted on IP + user agent, so rotating the
        // agent buys another vote. The IP is what actually has to be limited.
        RateLimiter::for('vote', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // sendBeacon from the share bar, and a counter anyone can inflate.
        RateLimiter::for('share', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // FULLTEXT against a longText column: the most expensive public GET.
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // The live blog and ticker poll every 20 seconds — three a minute per
        // open tab, so this is roughly twenty tabs before anyone notices.
        RateLimiter::for('polling', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // ── Reader writes ────────────────────────────────────────────────
        // Likes, reports, bookmarks and reading progress. The tracker posts
        // on every 25% of new ground, so a long read is a handful of requests.
        RateLimiter::for('engagement', fn (Request $request) => Limit::perMinute(60)->by($byUserOrIp($request)));

        // Posting and editing comments. CommentController's own limiter is the
        // one a reader meets; this stops a script reaching it.
        RateLimiter::for('comment-writes', fn (Request $request) => Limit::perMinute(20)->by($byUserOrIp($request)));

        // Password changes, profile edits, account deletion. Tight, because
        // updatePassword() checks the current password and is therefore an
        // oracle worth guessing against.
        RateLimiter::for('account', fn (Request $request) => Limit::perMinute(10)->by($byUserOrIp($request)));

        // ── Staff ────────────────────────────────────────────────────────
        // Generous enough that a busy editor never sees it, low enough that a
        // runaway script or a taken-over reporter account does.
        RateLimiter::for('admin', fn (Request $request) => Limit::perMinute(120)->by($byUserOrIp($request)));
    }

    /**
     * Laravel's built-in verification and reset mails are English-only. Rather
     * than subclass both notifications, override just their message builders —
     * the signed-URL generation and token handling stay untouched.
     */
    private function localiseAuthNotifications(): void
    {
        VerifyEmail::toMailUsing(function (User $user, string $url): MailMessage {
            return (new MailMessage)
                ->subject('ইমেইল যাচাই করুন — '.config('site.name_bn'))
                ->greeting('আসসালামু আলাইকুম, '.$user->name)
                ->line('আপনার অ্যাকাউন্টটি সক্রিয় করতে নিচের বোতামে ক্লিক করে ইমেইল ঠিকানাটি যাচাই করুন।')
                ->action('ইমেইল যাচাই করুন', $url)
                ->line('লিংকটি '.Bangla::digits(config('auth.verification.expire', 60)).' মিনিটের জন্য কার্যকর।')
                ->line('আপনি যদি এই অ্যাকাউন্টটি তৈরি না করে থাকেন, তবে এই বার্তাটি উপেক্ষা করুন।')
                ->salutation('ধন্যবাদান্তে, '.config('site.name_bn'));
        });

        ResetPassword::toMailUsing(function (User $user, string $token): MailMessage {
            $url = url(route('password.reset', ['token' => $token, 'email' => $user->email], false));

            return (new MailMessage)
                ->subject('পাসওয়ার্ড রিসেট — '.config('site.name_bn'))
                ->greeting('আসসালামু আলাইকুম, '.$user->name)
                ->line('আপনার অ্যাকাউন্টের পাসওয়ার্ড রিসেট করার অনুরোধ পাওয়া গেছে।')
                ->action('পাসওয়ার্ড রিসেট করুন', url($url))
                ->line('লিংকটি '.Bangla::digits(config('auth.passwords.users.expire', 60)).' মিনিটের জন্য কার্যকর।')
                ->line('আপনি এই অনুরোধ না করে থাকলে কোনো ব্যবস্থা নেওয়ার প্রয়োজন নেই।')
                ->salutation('ধন্যবাদান্তে, '.config('site.name_bn'));
        });
    }

    /**
     * Bangla presentation directives. Views use these instead of calling the
     * helper directly so number/date formatting stays consistent site-wide.
     */
    private function registerBladeDirectives(): void
    {
        // @bn(1234) -> ১২৩৪
        Blade::directive('bn', fn ($e) => "<?php echo \App\Support\Bangla::digits($e); ?>");

        // @bndate($article->published_at) -> ২৫ আগস্ট ২০২৬
        Blade::directive('bndate', fn ($e) => "<?php echo \App\Support\Bangla::date($e); ?>");

        // @bntime($date) -> রাত ৯:৪৫
        Blade::directive('bntime', fn ($e) => "<?php echo \App\Support\Bangla::time($e); ?>");

        // @bnago($date) -> ৩৮ মিনিট আগে
        Blade::directive('bnago', fn ($e) => "<?php echo \App\Support\Bangla::ago($e); ?>");

        // @bncount(12400) -> ১২.৪ হাজার
        Blade::directive('bncount', fn ($e) => "<?php echo \App\Support\Bangla::compact($e); ?>");

        // @bnfulldate -> মঙ্গলবার, ২৫ আগস্ট ২০২৬, ১০ ভাদ্র ১৪৩৩ বঙ্গাব্দ
        Blade::directive('bnfulldate', fn ($e) => '<?php echo \App\Support\Bangla::fullDate('.($e ?: 'null').'); ?>');
    }
}
