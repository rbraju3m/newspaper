<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('disk', 30)->default('public');
            $table->string('path');
            $table->string('filename');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();

            // Responsive derivatives written by the image pipeline:
            // {"thumb":"...","card":"...","hero":"...","webp":{...}}
            $table->json('conversions')->nullable();

            $table->string('alt')->nullable();
            $table->string('caption', 500)->nullable();
            $table->string('credit')->nullable();

            $table->timestamps();

            $table->index(['mime', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
