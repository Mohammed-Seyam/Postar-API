<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A carousel post may be all images, so it need not carry a single video.
        // post_media is the source of truth; these columns remain only for BC.
        Schema::table('posts', function (Blueprint $table) {
            $table->string('video_path')->nullable()->change();
            $table->string('video_url', 512)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('video_path')->nullable(false)->change();
            $table->string('video_url', 512)->nullable(false)->change();
        });
    }
};
