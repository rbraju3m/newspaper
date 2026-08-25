<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->string('name');            // Bangla display name
            $table->string('name_en')->nullable();
            $table->string('slug')->unique();  // ASCII slug, used in URLs

            // Materialised path ("khela/cricket") so a nested category resolves
            // in one query instead of walking the tree on every request.
            $table->string('path')->unique();

            $table->text('description')->nullable();
            $table->string('color', 7)->default('#C8102E');
            $table->string('icon', 40)->nullable();
            $table->string('layout_type', 30)->default('category_grid');

            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('show_in_nav')->default(true);
            $table->boolean('show_in_footer')->default(true);

            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();

            $table->unsignedInteger('articles_count')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'show_in_nav', 'position']);
            $table->index(['parent_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
