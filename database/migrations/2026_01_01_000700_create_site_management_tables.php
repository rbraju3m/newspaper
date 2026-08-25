<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('position', 60);            // matches config('site.ad_slots')
            $table->string('type', 20)->default('image'); // image | html | adsense
            $table->string('asset')->nullable();
            $table->text('html')->nullable();
            $table->string('url')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedSmallInteger('priority')->default(0);

            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestamps();

            // Slot resolution: active ads for a position within their flight dates.
            $table->index(['position', 'is_active', 'priority']);
        });

        Schema::create('polls', function (Blueprint $table) {
            $table->id();
            $table->string('question', 500);
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->boolean('multiple')->default(false);
            $table->timestamp('closes_at')->nullable();
            $table->unsignedInteger('votes_count')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'closes_at']);
        });

        Schema::create('poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
            $table->string('label', 300);
            $table->unsignedInteger('votes_count')->default(0);
            $table->unsignedTinyInteger('position')->default(0);

            $table->index(['poll_id', 'position']);
        });

        Schema::create('poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('poll_option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64)->nullable();  // hashed IP+UA for guests
            $table->timestamp('created_at')->nullable();

            // One vote per identity per poll, whichever identity we have.
            $table->unique(['poll_id', 'user_id']);
            $table->unique(['poll_id', 'fingerprint']);
        });

        // Drag-and-drop homepage layout, so editors reorder the front page
        // without a deploy.
        Schema::create('home_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('title')->nullable();          // override the section heading
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('limit')->default(5);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('column', 20)->default('main'); // main | sidebar
            $table->json('options')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'column', 'position']);
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('body')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string'); // string|bool|int|json|file
            $table->string('group', 40)->default('general');
            $table->timestamps();

            $table->index('group');
        });

        // Old URLs from the client's previous CMS, so migrating does not lose
        // inbound links or search rankings.
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from')->unique();
            $table->string('to');
            $table->unsignedSmallInteger('status')->default(301);
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('home_blocks');
        Schema::dropIfExists('poll_votes');
        Schema::dropIfExists('poll_options');
        Schema::dropIfExists('polls');
        Schema::dropIfExists('ads');
    }
};
