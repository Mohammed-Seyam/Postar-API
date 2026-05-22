<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_analytics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_target_id')->constrained('post_targets')->cascadeOnDelete();
            $table->string('platform');
            
            $table->bigInteger('views')->default(0);
            $table->bigInteger('likes')->default(0);
            $table->bigInteger('comments')->default(0);
            $table->bigInteger('shares')->default(0);
            $table->bigInteger('saves')->default(0);
            $table->bigInteger('reach')->default(0);
            $table->bigInteger('avg_watch_time_ms')->default(0);
            
            $table->dateTime('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_analytics');
    }
};
