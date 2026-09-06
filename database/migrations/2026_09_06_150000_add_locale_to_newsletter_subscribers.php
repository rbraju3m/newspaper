<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which edition a subscriber reads, and therefore which one they are mailed.
 *
 * A newsletter is the one surface where the reader is not standing on a URL
 * when the decision is made — the digest goes out from cron, hours later, and
 * nothing about that process knows what a subscriber can read. The only place
 * that knowledge can live is the row, and it is captured at the moment they
 * sign up: the box in the `/en` footer subscribes them to the English edition
 * and the Bangla one to the Bangla edition.
 *
 * Defaults to `bn` so every existing subscriber keeps exactly the mail they
 * have been getting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletter_subscribers', function (Blueprint $table) {
            $table->string('locale', 5)->default('bn')->after('frequency');
        });
    }

    public function down(): void
    {
        Schema::table('newsletter_subscribers', fn (Blueprint $table) => $table->dropColumn('locale'));
    }
};
