<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Timeline updates hung off an article of type `live`. Kept separate
        // from the article body so a running story can be appended to without
        // rewriting (and re-saving) the whole record on every update.
        Schema::create('live_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('headline', 300)->nullable();
            $table->text('body');
            $table->string('image')->nullable();
            $table->string('embed_url')->nullable();

            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_key')->default(false);   // shows in the summary rail
            $table->timestamp('published_at');
            $table->timestamps();

            // Rendering a live blog: newest first, pinned above.
            $table->index(['article_id', 'is_pinned', 'published_at']);
            // Polling asks "anything newer than X?"
            $table->index(['article_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_entries');
    }
};
