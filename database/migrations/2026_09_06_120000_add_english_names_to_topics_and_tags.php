<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * English names for the two taxonomies the English edition could not carry.
 *
 * `categories` had `name_en` from the first migration; `topics` and `tags`
 * never did, which is why both were **hidden** on `/en` rather than rendered
 * in Bangla — a Bangla chip on an English page links out of the edition and
 * reads as a mistake. These columns are what let those surfaces exist.
 *
 * All three are nullable, and the models fall back to the Bangla name rather
 * than to the slug: a heading reading "world-cup-2026" is worse than one
 * reading "বিশ্বকাপ ২০২৬" on an English page. A newsroom fills them in as it
 * goes, and nothing breaks while it has not.
 *
 * `topics.description_en` comes with `name_en` because a topic's description
 * is printed directly under its name on the topic page — an English heading
 * over a Bangla standfirst is worse than either alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->string('name_en', 150)->nullable()->after('name');
            $table->text('description_en')->nullable()->after('description');
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->string('name_en', 80)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('topics', fn (Blueprint $table) => $table->dropColumn(['name_en', 'description_en']));
        Schema::table('tags', fn (Blueprint $table) => $table->dropColumn('name_en'));
    }
};
