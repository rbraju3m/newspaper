<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Curated running-story clusters ("বিশ্বকাপ ২০২৬", "জাতীয় নির্বাচন"),
        // the pattern mzamin and Ittefaq use for ongoing events.
        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->string('color', 7)->default('#C8102E');

            $table->boolean('is_active')->default(true);
            $table->boolean('is_trending')->default(false);  // shows in the header chip row
            $table->unsignedSmallInteger('position')->default(0);

            $table->unsignedInteger('articles_count')->default(0);
            $table->timestamps();

            $table->index(['is_trending', 'is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topics');
    }
};
