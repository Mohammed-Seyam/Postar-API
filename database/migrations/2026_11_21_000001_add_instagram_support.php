<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-introduces Instagram (Instagram API with Instagram Login).
 *
 *  - social_accounts.platform_scoped_user_id: the app-scoped Instagram user id
 *    from the token exchange. platform_user_id keeps the professional account id
 *    used for publishing; Meta's signed_request may carry either.
 *  - Adds 'instagram' back to plans.allowed_platforms (removed by
 *    2026_05_17_204034_remove_instagram_from_database).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('platform_scoped_user_id')->nullable()->after('platform_user_id');
            $table->index(['platform', 'platform_scoped_user_id']);
        });

        $this->updateAllowedPlatforms(function (array $platforms) {
            if (! in_array('instagram', $platforms, true)) {
                $platforms[] = 'instagram';
            }

            return $platforms;
        });
    }

    public function down(): void
    {
        $this->updateAllowedPlatforms(
            fn (array $platforms) => array_values(array_filter($platforms, fn ($p) => $p !== 'instagram'))
        );

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropIndex(['platform', 'platform_scoped_user_id']);
            $table->dropColumn('platform_scoped_user_id');
        });
    }

    private function updateAllowedPlatforms(callable $transform): void
    {
        if (! Schema::hasTable('plans') || ! Schema::hasColumn('plans', 'allowed_platforms')) {
            return;
        }

        foreach (DB::table('plans')->get(['id', 'allowed_platforms']) as $plan) {
            $platforms = json_decode((string) $plan->allowed_platforms, true);

            if (! is_array($platforms)) {
                continue;
            }

            DB::table('plans')->where('id', $plan->id)->update([
                'allowed_platforms' => json_encode(array_values($transform($platforms))),
            ]);
        }
    }
};
