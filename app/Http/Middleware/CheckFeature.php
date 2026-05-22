<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeature
{
    /**
     * Handle an incoming request.
     * Use as: middleware('feature:can_bulk_upload') or middleware('feature:max_social_accounts,3')
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * $param string $feature The key of the feature in the JSON config
     * $param string|null $requiredLimit Optional. Use if checking a numeric limit.
     */
    public function handle(Request $request, Closure $next, string $feature, ?string $requiredLimit = null): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Boolean Check (e.g., 'can_bulk_upload')
        if ($requiredLimit === null) {
            if (!$user->hasFeature($feature)) {
                return response()->json([
                    'message' => 'Upgrade required.',
                    'error' => 'Feature not included in your current plan.'
                ], 403);
            }
        // Numeric Limit Check (e.g., 'max_social_accounts', '3')
        } else {
            $limit = $user->getFeatureLimit($feature, 0);

            // -1 means unlimited
            if ($limit !== -1 && (int)$requiredLimit > $limit) {
                 return response()->json([
                    'message' => 'Upgrade required.',
                    'error' => "Plan limit reached for {$feature}."
                ], 403);
            }
        }

        return $next($request);
    }
}
