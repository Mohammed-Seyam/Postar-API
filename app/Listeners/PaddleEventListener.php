<?php

namespace App\Listeners;

use App\Models\Plan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Paddle\Events\SubscriptionCanceled;
use Laravel\Paddle\Events\SubscriptionCreated;
use Laravel\Paddle\Events\SubscriptionUpdated;

class PaddleEventListener
{
    protected array $planMap;

    public function __construct()
    {
        // Map Paddle Price IDs to internal Plan IDs
        $this->planMap = [
            config('paddle.prices.creator_monthly') => 2,
            config('paddle.prices.creator_yearly') => 2,
            config('paddle.prices.pro_monthly') => 3,
            config('paddle.prices.pro_yearly') => 3,
        ];
    }

    public function handle($event): void
    {
        if ($event instanceof SubscriptionCreated || $event instanceof SubscriptionUpdated) {
            $this->updatePlan($event->subscription);
        } elseif ($event instanceof SubscriptionCanceled) {
            $this->cancelPlan($event->subscription);
        }
    }

    protected function updatePlan($subscription): void
    {
        $user = $subscription->billable;
        $priceId = $subscription->items->first()->price_id ?? null;

        if (! $priceId || ! $user) {
            return;
        }

        $planId = $this->planMap[$priceId] ?? 1;

        // Only update if changed or if it was canceled
        if ($user->plan_id !== $planId || $subscription->status !== 'active') {
            $user->forceFill([
                'plan_id' => $subscription->status === 'active' ? $planId : 1,
            ])->save();
            $this->clearPlanCache($user->id);
            Log::info("User {$user->id} plan updated to {$user->plan_id} via Paddle Event");
        }
    }

    protected function cancelPlan($subscription): void
    {
        $user = $subscription->billable;
        if ($user) {
            $user->forceFill(['plan_id' => 1])->save();
            $this->clearPlanCache($user->id);
            Log::info("User {$user->id} subscription canceled. Reverted to free plan.");
        }
    }

    protected function clearPlanCache(int $userId): void
    {
        Cache::forget("plan_context:{$userId}");
        Cache::forget("plan:{$userId}");
        try {
            Cache::tags(["user:{$userId}"])->flush();
        } catch (\Exception $e) {
            // Ignore
        }
    }
}
