<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_id')->constrained('posts')->cascadeOnDelete();

            // The kind of slide. Images power carousels; video keeps the existing flow.
            $table->enum('type', ['image', 'video']);

            $table->string('path');                 // storage key on R2/public disk
            $table->string('url', 512)->nullable(); // convenience absolute URL (may be presigned/expired)
            $table->string('thumbnail_path')->nullable();

            // 0-based order of the slide within the post.
            $table->unsignedInteger('position')->default(0);

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration')->nullable(); // seconds, video only
            $table->decimal('file_size_mb', 8, 2)->nullable();

            $table->json('meta')->nullable(); // per-slide extras (e.g. TikTok cover ms)

            $table->timestamps();

            $table->unique(['post_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_media');
    }
};
