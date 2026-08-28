<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links an ad to the `media` row its creative came from.
 *
 * Uploads have gone through `ImageService` for a while, so every creative
 * already has a media row and a WebP derivative ladder behind it — but the ad
 * only kept `asset`, a bare path, and the slot rendered that. The ladder
 * existed and nothing could reach it, so a 970×250 billboard was served at
 * whatever size it was uploaded at to a phone.
 *
 * Same shape as `articles.image_id` beside `articles.image`: the id is what
 * carries the derivatives, the path stays because it is what an external URL
 * or a pre-media-library import has. `nullOnDelete` because reaping a media
 * row two things share must blank the link rather than the ad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ads', function (Blueprint $table) {
            $table->foreignId('media_id')->nullable()->after('asset')
                ->constrained()->nullOnDelete();
        });

        // Existing creatives already have their row; it was simply never
        // recorded. Matching on the path is exact — `ImageService` is what
        // wrote both sides — and anything with no match stays null and keeps
        // rendering the original, which is the correct degradation for the one
        // creative that predates the media library.
        DB::table('ads')
            ->join('media', 'media.path', '=', 'ads.asset')
            ->update(['ads.media_id' => DB::raw('media.id')]);
    }

    public function down(): void
    {
        Schema::table('ads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('media_id');
        });
    }
};
