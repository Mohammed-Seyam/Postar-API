<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    /**
     * GET /api/subscription/config
     * يُرسل للـ Angular: client token + price IDs + خطة المستخدم الحالية
     */
    public function config(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'client_token' => config('cashier.client_token') ?? env('PADDLE_CLIENT_TOKEN'),
            'environment'  => config('cashier.sandbox') ? 'sandbox' : 'production',
            'current_plan' => $user->plan->name ?? 'free',
            'prices' => [
                'creator_monthly' => config('paddle.prices.creator_monthly'),
                'creator_yearly'  => config('paddle.prices.creator_yearly'),
                'pro_monthly'     => config('paddle.prices.pro_monthly'),
                'pro_yearly'      => config('paddle.prices.pro_yearly'),
            ],
            'paddle_customer_id' => $user->customer?->paddle_id,
        ]);
    }

    /**
     * POST /api/subscription/cancel
     * إلغاء الاشتراك من داخل الداشبورد
     */
    public function cancel(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->subscribed()) {
            return response()->json(['message' => 'لا يوجد اشتراك نشط'], 400);
        }

        try {
            $user->subscription()->cancel();

            return response()->json([
                'message' => 'تم إرسال طلب الإلغاء. ستنتهي خطتك في نهاية دورة الفوترة الحالية.',
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Paddle cancel error: ' . $e->getMessage());
            return response()->json(['message' => 'حدث خطأ أثناء الإلغاء'], 500);
        }
    }

    /**
     * GET /api/subscription/portal-url
     * يُرجع رابط Paddle Customer Portal لإدارة الدفع
     */
    public function portalUrl(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->customer) {
            return response()->json(['url' => null, 'message' => 'لا يوجد حساب Paddle مرتبط'], 200);
        }

        try {
            $url = $user->billingPortalUrl();
            return response()->json(['url' => $url]);
        } catch (\Exception $e) {
            return response()->json(['url' => null], 200);
        }
    }
}
