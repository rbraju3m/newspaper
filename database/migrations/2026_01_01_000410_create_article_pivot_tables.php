<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cross-posting: a story filed under খেলা can also surface in বাংলাদেশ.
        Schema::create('article_category', function (Blueprint $table) {
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->primary(['article_id', 'category_id']);
            $table->index('category_id');
        });

        Schema::create('article_tag', function (Blueprint $table) {
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['article_id', 'tag_id']);
            $table->index('tag_id');
        });

        Schema::create('article_topic', function (Blueprint $table) {
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->primary(['article_id', 'topic_id']);
            $table->index('topic_id');
        });

        // Editor-curated "related stories" that override the automatic picks.
        Schema::create('article_related', function (Blueprint $table) {
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_id')->constrained('articles')->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            $table->primary(['article_id', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_related');
        Schema::dropIfExists('article_topic');
        Schema::dropIfExists('article_tag');
        Schema::dropIfExists('article_category');
    }
};
