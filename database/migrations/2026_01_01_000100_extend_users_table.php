<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('name');
            $table->string('phone', 20)->nullable()->unique()->after('email');
            $table->string('role', 20)->default('reader')->index()->after('password');
            $table->string('status', 20)->default('active')->index()->after('role');
            $table->string('avatar')->nullable()->after('status');
            $table->string('designation')->nullable()->after('avatar');
            $table->text('bio')->nullable()->after('designation');
            $table->json('social')->nullable()->after('bio');
            $table->json('preferences')->nullable()->after('social');

            // Denormalised for author pages — kept in sync by the Article model.
            $table->unsignedInteger('articles_count')->default(0)->after('preferences');

            $table->timestamp('last_seen_at')->nullable()->after('articles_count');
            $table->string('last_ip', 45)->nullable()->after('last_seen_at');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'slug', 'phone', 'role', 'status', 'avatar', 'designation',
                'bio', 'social', 'preferences', 'articles_count', 'last_seen_at', 'last_ip',
            ]);
        });
    }
};
