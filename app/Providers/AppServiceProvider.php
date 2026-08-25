<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AdService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use App\Support\Bangla;
use App\View\Composers\AdminComposer;
use App\View\Composers\LayoutComposer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
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
                ->line('লিংকটি '.\App\Support\Bangla::digits(config('auth.verification.expire', 60)).' মিনিটের জন্য কার্যকর।')
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
                ->line('লিংকটি '.\App\Support\Bangla::digits(config('auth.passwords.users.expire', 60)).' মিনিটের জন্য কার্যকর।')
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
