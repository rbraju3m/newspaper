<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The English caption for a printed page's section.
 *
 * The e-paper is **one printed paper** — its page images are Bangla from
 * either edition of the site, and no column changes that. What this makes
 * navigable is the caption beside each thumbnail: `খেলা — পৃষ্ঠা ৬` tells a
 * Bangla reader which page to open, and told an English reader nothing.
 *
 * Nullable, and it falls back to **nothing** rather than to the Bangla
 * caption. The heading beside it is the page number, which always renders;
 * the section is the supplementary half, so an English reader gets
 * "Page 6" where a Bangla one gets "খেলা — পৃষ্ঠা ৬". Same rule as a job
 * title or a topic blurb, and the opposite of a name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epaper_pages', function (Blueprint $table) {
            $table->string('section_en', 60)->nullable()->after('section');
        });
    }

    public function down(): void
    {
        Schema::table('epaper_pages', fn (Blueprint $table) => $table->dropColumn('section_en'));
    }
};
