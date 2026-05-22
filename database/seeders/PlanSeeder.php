<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Upsert plans to avoid duplicates
        DB::table('plans')->upsert([
            [
                'name' => 'Free',
                'slug' => 'free',
                'price' => 0.00,
                'video_limit' => 5,
                'storage_limit' => 1024 * 1024 * 500, // 500MB
                'features' => json_encode([
                    'monthly_posts_limit' => 5,
                    'max_social_accounts' => 1,
                    'can_bulk_upload' => false,
                    'max_bulk_upload_size' => 0,
                    'can_customize_captions' => false,
                    'auto_retry_count' => 0,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Creator',
                'slug' => 'creator',
                'price' => 8.99,
                'video_limit' => 30,
                'storage_limit' => 1024 * 1024 * 1024 * 5, // 5GB
                'features' => json_encode([
                    'monthly_posts_limit' => 30,
                    'max_social_accounts' => 2,
                    'can_bulk_upload' => true,
                    'max_bulk_upload_size' => 5,
                    'can_customize_captions' => true,
                    'auto_retry_count' => 3,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'price' => 18.99,
                'video_limit' => 999999,
                'storage_limit' => 1024 * 1024 * 1024 * 50, // 50GB
                'features' => json_encode([
                    'monthly_posts_limit' => -1, // -1 means unlimited
                    'max_social_accounts' => 3,
                    'can_bulk_upload' => true,
                    'max_bulk_upload_size' => 20,
                    'can_customize_captions' => true,
                    'auto_retry_count' => -1, // -1 means unlimited or arbitrary large
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['slug'], ['name', 'price', 'video_limit', 'storage_limit', 'features', 'updated_at']);
    }
}
