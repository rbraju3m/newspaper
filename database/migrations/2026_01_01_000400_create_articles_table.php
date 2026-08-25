<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();

            // Primary section. Additional placements go through article_category.
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title', 500);
            $table->string('slug');
            $table->string('kicker', 200)->nullable();      // eyebrow line above the headline
            $table->string('subtitle', 500)->nullable();
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable();

            $table->string('type', 20)->default('news');
            $table->string('status', 20)->default('draft');

            // Lead image. Stored as both a media FK and a denormalised path so
            // listing queries never need the join.
            $table->foreignId('image_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('image')->nullable();
            $table->string('image_caption', 500)->nullable();
            $table->string('image_credit')->nullable();

            $table->string('video_url')->nullable();
            $table->unsignedSmallInteger('video_duration')->nullable();  // seconds

            // Placement flags driving the homepage.
            $table->boolean('is_lead')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_breaking')->default(false);
            $table->boolean('is_premium')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->boolean('allow_comments')->default(true);

            $table->timestamp('breaking_until')->nullable();  // ticker auto-expiry
            $table->timestamp('published_at')->nullable();

            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('shares')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedSmallInteger('reading_time')->default(1);  // minutes

            $table->string('locale', 5)->default('bn');
            $table->foreignId('translation_of')->nullable()->constrained('articles')->nullOnDelete();

            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('dateline', 120)->nullable();   // "ঢাকা", "নিজস্ব প্রতিবেদক"
            $table->string('source', 120)->nullable();     // wire credit

            $table->timestamps();
            $table->softDeletes();

            // A slug only needs to be unique inside its locale.
            $table->unique(['slug', 'locale']);

            // ── Query paths this table has to serve ──────────────────────────
            // Category landing pages and the homepage category blocks.
            $table->index(['category_id', 'status', 'published_at']);
            // Global "latest" feed and the archive.
            $table->index(['status', 'published_at']);
            // Homepage hero and featured rails.
            $table->index(['status', 'is_lead', 'published_at']);
            $table->index(['status', 'is_featured', 'published_at']);
            // The breaking ticker.
            $table->index(['is_breaking', 'breaking_until']);
            // Most-read widget.
            $table->index(['status', 'views']);
            // Video and photo hubs.
            $table->index(['type', 'status', 'published_at']);
            // Author pages.
            $table->index(['author_id', 'status', 'published_at']);
        });

        // Full-text search over the fields readers actually search. Bangla is
        // space-delimited, so the default parser tokenises it correctly; the
        // search service falls back to LIKE for queries below the minimum
        // token length (innodb_ft_min_token_size, default 3).
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE articles ADD FULLTEXT articles_fulltext (title, excerpt, body)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
