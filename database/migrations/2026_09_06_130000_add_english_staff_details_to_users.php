<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An English job title and biography for the people with bylines.
 *
 * These were the last surface `/en` had to hide. A reporter's `designation`
 * and `bio` are one piece of Bangla prose each, and a Bangla paragraph under
 * an English article reads as a mistake rather than as a byline — so the
 * author card was guarded on `Locale::isDefault()` and simply did not appear.
 *
 * **The name is deliberately not duplicated.** A person's name is their name
 * in either edition, and a `name_en` on `users` would be a romanisation the
 * newsroom has to keep in step with a spelling nobody agrees on. What differs
 * between editions is what the paper says *about* them, which is these two
 * columns.
 *
 * Both nullable, and both fall back to **nothing** in English rather than to
 * the Bangla text — the opposite of `categories.name_en` and for a reason
 * that is in `CLAUDE.md`: a name has to render or the page has no heading,
 * and neither of these is a heading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('designation_en', 120)->nullable()->after('designation');
            $table->text('bio_en')->nullable()->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['designation_en', 'bio_en']));
    }
};
