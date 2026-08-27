<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();

            // Nullable on purpose. Most readers of a news site are not signed
            // in, and breaking news is exactly what they want a notification
            // for. The browser's own permission prompt is the consent record;
            // an account is how a reader manages it afterwards, not a
            // precondition for having one.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            // The push service's URL for this one browser install. 500 is well
            // past what FCM, Mozilla and Apple actually issue, and still inside
            // InnoDB's 3072-byte index limit at four bytes per character.
            $table->string('endpoint', 500)->unique();

            // The subscription's own ECDH public key and auth secret. Messages
            // are encrypted to these, so without them a row is undeliverable.
            $table->string('public_key');
            $table->string('auth_token');

            // `aes128gcm` everywhere current; the older `aesgcm` is kept
            // readable because a subscription made years ago still reports it.
            $table->string('content_encoding', 20)->default('aes128gcm');

            $table->string('user_agent', 500)->nullable();

            // What the reader asked for. Only breaking news is sent today, but
            // the column is here rather than implied so turning a category
            // alert on later is a write, not a migration.
            $table->boolean('breaking')->default(true);

            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();

            // The send query is "every live subscription", so the flag leads.
            $table->index(['breaking', 'id']);
            $table->index('user_id');
        });

        Schema::table('articles', function (Blueprint $table) {
            // Set the moment an alert goes out, and checked before the next
            // one does. A push cannot be recalled, so the guard against
            // sending the same story twice has to be in the database rather
            // than in whoever is holding the mouse.
            $table->timestamp('push_sent_at')->nullable()->after('breaking_until');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('push_sent_at');
        });

        Schema::dropIfExists('push_subscriptions');
    }
};
