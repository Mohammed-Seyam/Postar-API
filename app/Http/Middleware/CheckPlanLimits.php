<?php

namespace App\Http\Middleware;

use App\Models\Plan;
use App\Support\LimitMessages;
use App\Support\VideoQuota;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CheckPlanLimits
{
    public function handle(Request $request, Closure $next, string $limit): mixed
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Skip limits for non-POST requests (like GET, PUT, DELETE), except for analytics payload checks
        if ($request->method() !== 'POST' && $limit !== 'analytics') {
            return $next($request);
        }

        $plan = $this->getPlan($user->plan_id);

        return match ($limit) {
            // 'posts' / 'uploads' are deprecated aliases of the single video quota.
            'videos', 'posts', 'uploads' => $this->checkVideos($request, $next, $user, $plan),
            'bulk_upload' => $this->checkBulk($request, $next, $plan),
            'file_size' => $this->checkFileSize($request, $next, $plan),
            'social_accounts' => $this->checkAccounts($request, $next, $user, $plan),
            'analytics' => $this->checkAnalytics($request, $next, $plan),
            'custom_caption' => $this->checkCaption($request, $next, $plan),
            'storage' => $this->checkStorage($request, $next, $user, $plan),
            default => $next($request),
        };
    }

    private function getPlan(int $planId): Plan
    {
        return Cache::remember('plan:'.$planId, 300, fn () => Plan::find($planId));
    }

    /**
     * One monthly quota for videos. A video sent to several platforms is still
     * one unit, so requests that act on an already-counted video (scheduling an
     * uploaded draft, adding a carousel slide) consume nothing.
     */
    private function checkVideos($req, $next, $user, $plan): mixed
    {
        $used = VideoQuota::used($user);
        $incoming = $this->incomingVideos($req, $user);

        if (($used + $incoming) > $plan->videos_per_month) {
            return $this->limitResponse('videos', $used, $plan->videos_per_month, $plan->name);
        }

        return $next($req);
    }

    private function incomingVideos($req, $user): int
    {
        $existing = $req->route('post') ?? $req->route('video') ?? $req->input('video_id') ?? $req->input('post_id');
        if ($existing instanceof Model) {
            $existing = $existing->getKey();
        }

        if ($existing && VideoQuota::alreadyCounted($user, (string) $existing)) {
            return 0;
        }

        return max(1, count($req->file('files', [])));
    }

    private function checkBulk($req, $next, $plan): mixed
    {
        $count = count($req->file('files', []));
        if ($count > $plan->bulk_upload_limit) {
            return $this->limitResponse('bulk', $count, $plan->bulk_upload_limit, $plan->name);
        }

        return $next($req);
    }

    private function checkFileSize($req, $next, $plan): mixed
    {
        // For presigned URL generation, file size is typically passed in request body
        $sizeMb = (float) $req->input('file_size_mb', 0);
        if ($sizeMb > $plan->max_file_size_mb && $plan->max_file_size_mb > 0) {
            return $this->limitResponse('file_size', $sizeMb, $plan->max_file_size_mb, $plan->name);
        }

        return $next($req);
    }

    private function checkAccounts($req, $next, $user, $plan): mixed
    {
        $connected = \DB::table('social_accounts')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->count();

        if ($connected >= $plan->max_social_accounts) {
            return $this->limitResponse('accounts', $connected, $plan->max_social_accounts, $plan->name);
        }

        return $next($req);
    }

    private function checkAnalytics($req, $next, $plan): mixed
    {
        if ($plan->analytics_days === 0) {
            return $this->limitResponse('analytics', 0, 0, $plan->name);
        }

        return $next($req);
    }

    private function checkCaption($req, $next, $plan): mixed
    {
        return $next($req);
    }

    private function checkStorage($req, $next, $user, $plan): mixed
    {
        // Avoid summing un-tracked file_size_mb columns to prevent SQL exceptions
        $usedGb = 0;

        $incomingGb = (float) ($req->input('file_size_mb', 0)) / 1024;

        if (($usedGb + $incomingGb) > $plan->storage_gb) {
            return $this->limitResponse('storage', round($usedGb, 2), $plan->storage_gb, $plan->name);
        }

        return $next($req);
    }

    private function limitResponse(string $limitType, mixed $current, mixed $max, string $planName): \Illuminate\Http\JsonResponse
    {
        $messages = LimitMessages::get($limitType, $current, $max, $planName);

        return response()->json([
            'error' => 'plan_limit_reached',
            'limit_type' => $limitType,
            'current' => $current,
            'maximum' => $max,
            'plan' => $planName,
            'message' => $messages['user_message'],
            'cta_text' => $messages['cta_text'],
            'cta_action' => $messages['cta_action'],
            'upgrade_to' => $messages['upgrade_to'],
        ], 402);
    }
}
