<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Plan;
use App\Models\Post;
use App\Models\PostTarget;

class UserController extends Controller
{
    public function planContext(Request $request): JsonResponse
    {
        $user = $request->user();
        $plan = Cache::remember('plan:' . $user->plan_id, 300, fn() => Plan::find($user->plan_id));

        $postsUsed = PostTarget::whereHas('post', fn($q) => $q->where('user_id', $user->id))
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->whereNotIn('status', ['cancelled'])
            ->count();

        $uploadsUsed = Post::where('user_id', $user->id)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->whereNotIn('status', ['failed', 'deleted'])
            ->count();

        $storageUsedGb = Post::where('user_id', $user->id)
            ->whereNotIn('status', ['deleted'])
            ->sum('file_size_mb') / 1024;

        $accountsConnected = DB::table('social_accounts')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->count();

        return response()->json([
            'plan' => [
                'id'           => $plan->id,
                'name'         => $plan->name,
                'display_name' => $plan->display_name,
            ],
            'limits' => [
                'max_posts_per_month'   => $plan->max_posts_per_month,
                'max_uploads_per_month' => $plan->max_uploads_per_month,
                'max_file_size_mb'      => $plan->max_file_size_mb,
                'bulk_upload_limit'     => $plan->bulk_upload_limit,
                'max_social_accounts'   => $plan->max_social_accounts,
                'analytics_days'        => $plan->analytics_days,
                'storage_gb'            => $plan->storage_gb,
                'custom_caption'        => (bool) $plan->custom_caption,
                'auto_retry'            => (bool) $plan->auto_retry,
            ],
            'usage' => [
                'posts_this_month'   => $postsUsed,
                'uploads_this_month' => $uploadsUsed,
                'storage_used_gb'    => round($storageUsedGb, 3),
                'accounts_connected' => $accountsConnected,
            ],
            'plan_expires_at' => $user->plan_expires_at,
        ]);
    }
}
