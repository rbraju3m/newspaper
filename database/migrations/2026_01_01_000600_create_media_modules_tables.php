<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('galleries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title', 500);
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('cover')->nullable();

            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedSmallInteger('images_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });

        Schema::create('gallery_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('path');
            $table->string('caption', 500)->nullable();
            $table->string('credit')->nullable();
            $table->unsignedSmallInteger('position')->default(0);

            $table->index(['gallery_id', 'position']);
        });

        // E-paper: one issue per date per edition, each with page images.
        Schema::create('epapers', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('edition', 60)->default('main');   // main / dhaka / chittagong
            $table->string('pdf')->nullable();
            $table->string('cover')->nullable();
            $table->unsignedTinyInteger('pages_count')->default(0);
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->unique(['date', 'edition']);
            $table->index(['is_published', 'date']);
        });

        Schema::create('epaper_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('epaper_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('page_number');
            $table->string('image');
            $table->string('thumbnail')->nullable();
            $table->string('section', 60)->nullable();       // "প্রথম পাতা", "খেলা"
            $table->string('pdf')->nullable();

            $table->unique(['epaper_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epaper_pages');
        Schema::dropIfExists('epapers');
        Schema::dropIfExists('gallery_images');
        Schema::dropIfExists('galleries');
    }
};
