<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * English words for the photo galleries.
 *
 * A gallery is the same shape as the e-paper: the **photographs are the
 * content** and they are language-neutral, so there is one gallery and both
 * editions show it. What differs is the words around them — the title, the
 * blurb and the per-image caption.
 *
 * `slug` deliberately stays single. `Gallery::uniqueSlug()` already says it is
 * "unique across the whole table because galleries are not scoped by locale",
 * and that is still true: one gallery, one address per edition, the way an
 * e-paper issue works and unlike an article.
 *
 * `title_en` falls back to the Bangla title — it is a heading, and a page
 * needs one. `description_en` and `caption_en` fall back to nothing, because
 * neither is a heading and Bangla prose under an English title reads as a
 * mistake. That split is the rule the rest of the edition follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->string('title_en')->nullable()->after('title');
            $table->text('description_en')->nullable()->after('description');
        });

        Schema::table('gallery_images', function (Blueprint $table) {
            $table->string('caption_en', 500)->nullable()->after('caption');
        });
    }

    public function down(): void
    {
        Schema::table('galleries', fn (Blueprint $table) => $table->dropColumn(['title_en', 'description_en']));
        Schema::table('gallery_images', fn (Blueprint $table) => $table->dropColumn('caption_en'));
    }
};
