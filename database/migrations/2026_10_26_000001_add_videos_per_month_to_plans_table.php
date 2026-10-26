<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-launch plan restructure.
 *
 * - One monthly quota: videos_per_month. A video published to several
 *   platforms consumes a single unit.
 * - max_posts_per_month / max_uploads_per_month are DEPRECATED: they are no
 *   longer read for enforcement. The columns are kept (and mirrored to
 *   videos_per_month) so older readers never see a NULL "unlimited" value.
 * - No plan is unlimited anymore; Pro has a fair-use cap.
 */
return new class extends Migration
{
    private const PLANS = [
        'free' => [
            'display_name' => 'مجاني',
            'price_monthly' => 0.00,
            'videos_per_month' => 5,
            'max_social_accounts' => 2,
            'storage_gb' => 1,
            'max_file_size_mb' => 150,
            'analytics_days' => 0,
            'custom_caption' => 0,
        ],
        'creator' => [
            'display_name' => 'المبدع',
            'price_monthly' => 8.99,
            'videos_per_month' => 30,
            'max_social_accounts' => 3,
            'storage_gb' => 5,
            'max_file_size_mb' => 150,
            'analytics_days' => 0,
            'custom_caption' => 1,
        ],
        'pro' => [
            'display_name' => 'المحترف',
            'price_monthly' => 18.99,
            'videos_per_month' => 150,
            'max_social_accounts' => 5,
            'storage_gb' => 20,
            'max_file_size_mb' => 150,
            'analytics_days' => 90,
            'custom_caption' => 1,
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('plans', 'videos_per_month')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->unsignedInteger('videos_per_month')->default(5)->after('price_yearly');
            });
        }

        foreach (self::PLANS as $name => $values) {
            DB::table('plans')->where('name', $name)->update($values + [
                // Deprecated mirrors — see class docblock.
                'max_posts_per_month' => $values['videos_per_month'],
                'max_uploads_per_month' => $values['videos_per_month'],
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('plans', 'videos_per_month')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->dropColumn('videos_per_month');
            });
        }
    }
};
