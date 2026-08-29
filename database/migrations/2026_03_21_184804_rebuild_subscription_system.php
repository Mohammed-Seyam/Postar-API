<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        // Drop old tables
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');

        Schema::create('plans', function (Blueprint $table) {
            $table->tinyIncrements('id');
            $table->string('name', 30);
            $table->string('display_name', 30);
            $table->decimal('price_monthly', 6, 2)->default(0.00);
            $table->decimal('price_yearly', 8, 2)->default(0.00);
            $table->smallInteger('max_posts_per_month')->unsigned()->nullable();
            $table->smallInteger('max_uploads_per_month')->unsigned()->nullable();
            $table->smallInteger('max_file_size_mb')->unsigned()->default(150);
            $table->tinyInteger('bulk_upload_limit')->unsigned()->default(1);
            $table->tinyInteger('max_social_accounts')->unsigned()->default(1);
            $table->json('allowed_platforms');
            $table->smallInteger('analytics_days')->unsigned()->default(0);
            $table->tinyInteger('storage_gb')->unsigned()->default(1);
            $table->smallInteger('retention_days')->unsigned()->default(30);
            $table->boolean('custom_caption')->default(1);
            $table->boolean('auto_retry')->default(0);
            $table->timestamps();
        });

        // Insert exactly the plans requested
        DB::table('plans')->insert([
            [
                'id' => 1, 'name' => 'free', 'display_name' => 'Free', 'price_monthly' => 0.00, 'price_yearly' => 0.00,
                'max_posts_per_month' => 5, 'max_uploads_per_month' => 5, 'max_file_size_mb' => 150,
                'bulk_upload_limit' => 1, 'max_social_accounts' => 1, 'allowed_platforms' => json_encode(['tiktok', 'youtube']),
                'analytics_days' => 0, 'storage_gb' => 1, 'retention_days' => 30, 'custom_caption' => 1, 'auto_retry' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => 2, 'name' => 'creator', 'display_name' => 'Creator', 'price_monthly' => 9.00, 'price_yearly' => 81.00,
                'max_posts_per_month' => 20, 'max_uploads_per_month' => 20, 'max_file_size_mb' => 150,
                'bulk_upload_limit' => 5, 'max_social_accounts' => 2, 'allowed_platforms' => json_encode(['tiktok', 'youtube']),
                'analytics_days' => 30, 'storage_gb' => 5, 'retention_days' => 60, 'custom_caption' => 1, 'auto_retry' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => 3, 'name' => 'pro', 'display_name' => 'Pro', 'price_monthly' => 19.00, 'price_yearly' => 171.00,
                'max_posts_per_month' => null, 'max_uploads_per_month' => null, 'max_file_size_mb' => 150,
                'bulk_upload_limit' => 20, 'max_social_accounts' => 3, 'allowed_platforms' => json_encode(['tiktok', 'youtube']),
                'analytics_days' => 90, 'storage_gb' => 20, 'retention_days' => 90, 'custom_caption' => 1, 'auto_retry' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        // Add columns to existing users table
        Schema::table('users', function (Blueprint $table) {
            $table->tinyInteger('plan_id')->unsigned()->default(1);
            $table->timestamp('plan_expires_at')->nullable();

            if (config('database.default') !== 'sqlite') {
                $table->foreign('plan_id')->references('id')->on('plans');
            }
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
            $table->dropColumn('plan_id');
            $table->dropColumn('plan_expires_at');
        });
        Schema::dropIfExists('plans');
        Schema::enableForeignKeyConstraints();
    }
};
