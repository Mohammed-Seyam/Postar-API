<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Remove instagram from plans
        $plans = DB::table('plans')->get();
        foreach ($plans as $plan) {
            $platforms = json_decode($plan->allowed_platforms, true);
            if (is_array($platforms)) {
                $platforms = array_values(array_filter($platforms, fn($p) => $p !== 'instagram'));
                DB::table('plans')->where('id', $plan->id)->update([
                    'allowed_platforms' => json_encode($platforms)
                ]);
            }
        }

        // Delete any scheduled_posts for instagram (old system)
        if (Schema::hasTable('scheduled_posts')) {
            DB::table('scheduled_posts')->where('platform', 'instagram')->delete();
        }

        // Delete instagram post targets (new system)
        if (Schema::hasTable('social_accounts') && Schema::hasTable('post_targets')) {
            $instagramAccounts = DB::table('social_accounts')->where('platform', 'instagram')->pluck('id');
            if ($instagramAccounts->isNotEmpty()) {
                DB::table('post_analytics')->whereIn('post_target_id', function ($query) use ($instagramAccounts) {
                    $query->select('id')->from('post_targets')->whereIn('social_account_id', $instagramAccounts);
                })->delete();
                
                DB::table('post_targets')->whereIn('social_account_id', $instagramAccounts)->delete();
                DB::table('social_accounts')->where('platform', 'instagram')->delete();
            }
        }
    }

    public function down(): void
    {
        // No safe down operation
    }
};
