<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletter_subscribers', function (Blueprint $table) {
            // When this address last received an edition. The guard against a
            // second cron run — or a hand re-run after a partial failure —
            // mailing the same digest twice in one morning.
            $table->timestamp('last_sent_at')->nullable()->after('unsubscribed_at');

            // The send list is "verified, not unsubscribed, of this frequency,
            // not already sent to today", and it is the only query that walks
            // this table at any size.
            $table->index(['frequency', 'verified_at', 'unsubscribed_at'], 'newsletter_send_list_index');
        });
    }

    public function down(): void
    {
        Schema::table('newsletter_subscribers', function (Blueprint $table) {
            $table->dropIndex('newsletter_send_list_index');
            $table->dropColumn('last_sent_at');
        });
    }
};
