<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class WebhookController extends Controller
{
    public function handlePaddle(Request $request): JsonResponse
    {
        // Log كل الـ payload عشان نشوف إيه اللي بيجي
        \Illuminate\Support\Facades\Log::info('Paddle webhook payload', [
            'event_type'  => $request->input('event_type'),
            'custom_data' => $request->input('data.custom_data'),
            'price_id'    => $request->input('data.items.0.price.id'),
            'full_data'   => $request->all(),
        ]);

        $event = $request->input('event_type');
        $data  = $request->input('data');

        // تأكد إن $data مش null
        if (!$data) {
            \Illuminate\Support\Facades\Log::error('Paddle webhook: data is null');
            return response()->json(['ok' => true]);
        }

        match($event) {
            'subscription.created'   => $this->onCreated($data),
            'subscription.updated'   => $this->onUpdated($data),
            'subscription.cancelled' => $this->onCancelled($data),
            'subscription.expired'   => $this->onExpired($data),
            'payment.failed'         => $this->onPaymentFailed($data),
            default                  => null,
        };

        return response()->json(['ok' => true]);
    }

    private function verifyPaddleSignature(Request $request): void
    {
        // Paddle signature verification logic would go here
        // For MVP, we assume HTTPS endpoint security or basic auth
    }

    private function onCreated(array $data): void
    {
        $userId  = $data['custom_data']['userId'] ?? $data['custom_data']['user_id'] ?? null;
        $priceId = $data['items'][0]['price']['id'] ?? '';
        $planId  = $this->mapPaddlePlanId($priceId);

        \Illuminate\Support\Facades\Log::info('onCreated debug', [
            'userId'  => $userId,
            'priceId' => $priceId,
            'planId'  => $planId, // لو هذا = 1 فالـ map فاشل
        ]);

        if (!$userId) return;

        $user = User::where('id', $userId)->first();

        \Illuminate\Support\Facades\Log::info('user found', [
            'found'           => $user ? 'YES' : 'NO',
            'current_plan_id' => $user?->plan_id,
        ]);

        if (!$user) return;

        $result = $user->update([
            'plan_id'         => $planId,
            'plan_expires_at' => null,
        ]);

        \Illuminate\Support\Facades\Log::info('update result', [
            'result'      => $result,
            'new_plan_id' => $user->fresh()->plan_id,
        ]);

        Cache::forget('plan_context:' . $userId);
        Cache::forget('plan:' . $userId);
    }

    private function onUpdated(array $data): void
    {
        $this->onCreated($data); // Same logic applies for plan changes
    }
    
    private function onCancelled(array $data): void
    {
        $userId = $data['custom_data']['userId'] ?? $data['custom_data']['user_id'] ?? null;

        if (!$userId) return;

        $user = User::where('id', $userId)->first();

        if (!$user) {
            \Illuminate\Support\Facades\Log::error('Paddle: user not found', ['userId' => $userId]);
            return;
        }

        $user->update([
            'plan_id'        => 1, // Revert to free
            'plan_expires_at'=> now(), // Or use Paddle's cancellation effective date
        ]);

        Cache::forget('plan_context:' . $userId);
        Cache::forget('plan:' . $userId);
    }

    private function onExpired(array $data): void
    {
        $userId = $data['custom_data']['userId'] ?? $data['custom_data']['user_id'] ?? null;

        if (!$userId) return;

        $user = User::where('id', $userId)->first();

        if (!$user) {
            \Illuminate\Support\Facades\Log::error('Paddle: user not found', ['userId' => $userId]);
            return;
        }

        $user->update([
            'plan_id'        => 1,
            'plan_expires_at'=> now(),
        ]);

        Cache::forget('plan_context:' . $userId);
        Cache::forget('plan:' . $userId);
    }
    
    private function onPaymentFailed(array $data): void
    {
        // Logic to notify user or gracefully downgrade
    }

    private function mapPaddlePlanId(string $priceId): int
    {
        $mapped = match($priceId) {
            config('paddle.prices.creator_monthly') => 2,
            config('paddle.prices.creator_yearly')  => 2,
            config('paddle.prices.pro_monthly')     => 3,
            config('paddle.prices.pro_yearly')      => 3,
            default                                   => 1,
        };

        \Illuminate\Support\Facades\Log::info('mapPaddlePlanId debug', [
            'priceId' => $priceId,
            'mapped'  => $mapped,
            'configs' => [
                'creator_monthly' => config('paddle.prices.creator_monthly'),
                'creator_yearly'  => config('paddle.prices.creator_yearly'),
                'pro_monthly'     => config('paddle.prices.pro_monthly'),
                'pro_yearly'      => config('paddle.prices.pro_yearly'),
            ],
        ]);

        return $mapped;
    }
}
