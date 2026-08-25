<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('body');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedSmallInteger('replies_count')->default(0);
            $table->unsignedTinyInteger('reports_count')->default(0);

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Rendering a thread: approved top-level comments oldest-first.
            $table->index(['article_id', 'status', 'parent_id', 'created_at']);
            // The moderation queue.
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('comment_likes', function (Blueprint $table) {
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['comment_id', 'user_id']);
        });

        Schema::create('bookmarks', function (Blueprint $table) {
            // Stamped by the database: attach()/toggle() do not write pivot
            // timestamps unless the relation declares withTimestamps(), which
            // would also demand an updated_at column this table does not need.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['user_id', 'article_id']);
            // "My saved stories", newest first.
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('reactions', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('like');
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['user_id', 'article_id']);
            $table->index(['article_id', 'type']);
        });

        Schema::create('reading_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();

            // 0-100, driven by the reading-progress bar, so "continue reading"
            // can resume where the reader stopped.
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedSmallInteger('seconds')->default(0);
            $table->timestamp('read_at');

            // One row per user/article, updated on revisit rather than appended.
            $table->unique(['user_id', 'article_id']);
            $table->index(['user_id', 'read_at']);
            // Feeds the personalised recommender.
            $table->index(['article_id', 'read_at']);
        });

        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->string('token', 64)->unique();      // verify + unsubscribe link
            $table->json('categories')->nullable();     // opt-in section filter
            $table->string('frequency', 20)->default('daily');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['verified_at', 'unsubscribed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
        Schema::dropIfExists('reading_history');
        Schema::dropIfExists('reactions');
        Schema::dropIfExists('bookmarks');
        Schema::dropIfExists('comment_likes');
        Schema::dropIfExists('comments');
    }
};
