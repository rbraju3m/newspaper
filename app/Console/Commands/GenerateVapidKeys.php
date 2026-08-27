<?php

namespace App\Console\Commands;

use App\Services\PushService;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Generates the VAPID key pair Web Push identifies this application by.
 *
 * It prints rather than writes. Rotating the pair silently invalidates every
 * subscription on the site — a browser will not accept a message signed by a
 * key it did not subscribe under, and there is no way to tell it the key
 * changed — so overwriting an existing `.env` value is not something a command
 * should do while somebody is looking the other way.
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'push:keys {--force : Print a new pair even though one is already configured}';

    protected $description = 'Generate a VAPID key pair for Web Push';

    public function handle(PushService $push): int
    {
        if ($push->configured() && ! $this->option('force')) {
            $this->components->warn('A key pair is already configured.');

            $this->line('');
            $this->line('  Replacing it unsubscribes every browser on the site: a push message');
            $this->line('  signed by a new key is rejected by a browser that subscribed under');
            $this->line('  the old one, and nothing can tell it to re-subscribe. Re-run with');
            $this->line('  <options=bold>--force</> if that is genuinely what you want.');
            $this->line('');

            return self::FAILURE;
        }

        $keys = VAPID::createVapidKeys();

        $this->components->info('Add these to .env — both halves, on the server that will send.');
        $this->line('');
        $this->line('PUSH_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('PUSH_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('');
        $this->components->twoColumnDetail('Then', 'php artisan config:clear');
        $this->line('');
        $this->components->warn('The private key is a credential. It is not printed again.');

        return self::SUCCESS;
    }
}
