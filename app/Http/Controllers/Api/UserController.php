<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Post;
use App\Support\VideoQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function planContext(Request $request): JsonResponse
    {
        $user = $request->user();
        $plan = Cache::remember('plan:'.$user->plan_id, 300, fn () => Plan::find($user->plan_id));

        // Same count the plan:videos middleware enforces (1 video = 1 unit).
        $videosUsed = VideoQuota::used($user);

        $storageUsedGb = Post::where('user_id', $user->id)
            ->whereNotIn('status', ['deleted'])
            ->sum('file_size_mb') / 1024;

        $accountsConnected = DB::table('social_accounts')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->count();

        $subscription = $user->subscription();

        return response()->json([
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'display_name' => $plan->display_name,
            ],
            'limits' => [
                'videos_per_month' => $plan->videos_per_month,
                'max_file_size_mb' => $plan->max_file_size_mb,
                'bulk_upload_limit' => $plan->bulk_upload_limit,
                'max_social_accounts' => $plan->max_social_accounts,
                'analytics_days' => $plan->analytics_days,
                'storage_gb' => $plan->storage_gb,
                'custom_caption' => (bool) $plan->custom_caption,
                'auto_retry' => (bool) $plan->auto_retry,
            ],
            'usage' => [
                'videos_this_month' => $videosUsed,
                'storage_used_gb' => round($storageUsedGb, 3),
                'accounts_connected' => $accountsConnected,
            ],
            'plan_expires_at' => $user->plan_expires_at,
            'cancel_at_period_end' => $subscription ? (bool) $subscription->cancel_at_period_end : false,
            'cancel_at' => $subscription && $subscription->cancel_at ? $subscription->cancel_at->toIso8601String() : null,
        ]);
    }
}
