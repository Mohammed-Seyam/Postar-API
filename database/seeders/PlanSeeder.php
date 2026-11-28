<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Single source of truth for plan limits. 1 video = 1 unit of the monthly
     * quota no matter how many platforms it is published to. storage_gb is
     * enforced by the backend but never shown on pricing cards. price_yearly is
     * left untouched: annual billing is disabled for now.
     */
    public function run(): void
    {
        $plans = [
            [
                'id' => 1, 'name' => 'free', 'display_name' => 'مجاني',
                'price_monthly' => 0.00,
                'videos_per_month' => 5, 'max_social_accounts' => 2, 'storage_gb' => 1,
                'max_file_size_mb' => 150, 'bulk_upload_limit' => 1,
                'analytics_days' => 0, 'retention_days' => 30, 'custom_caption' => 0, 'auto_retry' => 0,
            ],
            [
                'id' => 2, 'name' => 'creator', 'display_name' => 'المبدع',
                'price_monthly' => 8.99,
                'videos_per_month' => 30, 'max_social_accounts' => 3, 'storage_gb' => 5,
                'max_file_size_mb' => 150, 'bulk_upload_limit' => 5,
                'analytics_days' => 0, 'retention_days' => 60, 'custom_caption' => 1, 'auto_retry' => 1,
            ],
            [
                'id' => 3, 'name' => 'pro', 'display_name' => 'المحترف',
                'price_monthly' => 18.99,
                'videos_per_month' => 150, 'max_social_accounts' => 5, 'storage_gb' => 20, // 150 = fair-use cap
                'max_file_size_mb' => 150, 'bulk_upload_limit' => 20,
                'analytics_days' => 90, 'retention_days' => 90, 'custom_caption' => 1, 'auto_retry' => 1,
            ],
        ];

        foreach ($plans as $plan) {
            DB::table('plans')->updateOrInsert(['id' => $plan['id']], $plan + [
                // Deprecated: no longer enforced, mirrored for older readers.
                'max_posts_per_month' => $plan['videos_per_month'],
                'max_uploads_per_month' => $plan['videos_per_month'],
                'allowed_platforms' => json_encode(['tiktok', 'youtube', 'instagram']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
