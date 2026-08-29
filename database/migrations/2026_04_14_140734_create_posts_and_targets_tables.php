<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop database views that might be using the tables we are about to drop
        Schema::dropIfExists('usage_statistics_view');

        Schema::dropIfExists('scheduled_posts');
        Schema::dropIfExists('videos');

        Schema::create('posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('caption')->nullable();
            $table->string('video_path');
            $table->string('video_url', 512);

            $table->decimal('file_size_mb', 8, 2)->nullable();
            $table->integer('duration')->default(0);

            $table->dateTime('scheduled_at')->nullable();
            $table->enum('status', ['draft', 'scheduled', 'publishing', 'published', 'failed'])->default('draft');

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('post_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_id')->constrained('posts')->cascadeOnDelete();

            $table->foreignId('social_account_id')->constrained('social_accounts')->cascadeOnDelete();

            $table->enum('status', ['pending', 'uploading', 'processing', 'published', 'failed'])->default('pending');
            $table->string('platform_post_id')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('published_at')->nullable();

            $table->timestamps();

            $table->unique(['post_id', 'social_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_targets');
        Schema::dropIfExists('posts');

        Schema::create('videos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('thumbnail_path')->nullable();
            $table->integer('duration')->default(0);
            $table->enum('status', ['draft', 'scheduled', 'published', 'failed'])->default('draft');
            $table->string('title')->nullable();
            $table->decimal('file_size_mb', 8, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('scheduled_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('video_id')->constrained('videos')->cascadeOnDelete();
            $table->string('platform');
            $table->dateTime('publish_at');
            $table->text('caption')->nullable();
            $table->text('hashtags')->nullable();
            $table->enum('status', ['pending', 'publishing', 'published', 'failed'])->default('pending');
            $table->string('external_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique('video_id');
        });
    }
};
