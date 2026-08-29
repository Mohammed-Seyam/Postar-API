<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    /**
     * GET /api/subscription/config
     * sends to Angular: client token + price IDs + current plan
     */
    public function config(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'client_token' => config('cashier.client_token') ?? config('paddle.client_token'),
            'environment' => config('cashier.sandbox') ? 'sandbox' : 'production',
            'current_plan' => $user->plan->name ?? 'free',
            'prices' => [
                'creator_monthly' => config('paddle.prices.creator_monthly'),
                'creator_yearly' => config('paddle.prices.creator_yearly'),
                'pro_monthly' => config('paddle.prices.pro_monthly'),
                'pro_yearly' => config('paddle.prices.pro_yearly'),
            ],
            'paddle_customer_id' => $user->customer?->paddle_id,
        ]);
    }

    /**
     * GET /api/subscription/billing
     * returns billing details, next billing date/amount, and transaction logs
     */
    public function billingInfo(Request $request): JsonResponse
    {
        $user = $request->user();

        // Self-healing: If user has a paid plan but is missing DB subscription records, auto-create them
        if ($user->plan_id > 1) {
            if (! $user->customer) {
                DB::table('customers')->updateOrInsert(
                    [
                        'billable_type' => get_class($user),
                        'billable_id' => $user->id,
                    ],
                    [
                        'paddle_id' => 'ctm_mock_'.str_replace('-', '', $user->id),
                        'name' => $user->name ?? 'Customer',
                        'email' => $user->email,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
                $user->unsetRelation('customer');
            }

            $subscription = Subscription::where('user_id', $user->id)->first();
            if (! $subscription) {
                $isYearly = false;
                $currentPeriodEnd = $user->plan_expires_at ?? now()->addMonth();

                if ($user->plan_expires_at) {
                    $diffDays = now()->diffInDays($user->plan_expires_at);
                    if ($diffDays > 40) {
                        $isYearly = true;
                    }
                } else {
                    $lastTx = $user->transactions()->where('status', 'completed')->orderBy('billed_at', 'desc')->first();
                    if ($lastTx && (float) $lastTx->total > 30.0) {
                        $isYearly = true;
                        $currentPeriodEnd = $lastTx->billed_at->addYear();
                    } elseif ($lastTx) {
                        $currentPeriodEnd = $lastTx->billed_at->addMonth();
                    }
                }

                $priceId = null;
                if ($user->plan_id == 2) {
                    $priceId = $isYearly ? config('paddle.prices.creator_yearly') : config('paddle.prices.creator_monthly');
                } elseif ($user->plan_id == 3) {
                    $priceId = $isYearly ? config('paddle.prices.pro_yearly') : config('paddle.prices.pro_monthly');
                }

                $subscription = Subscription::create([
                    'user_id' => $user->id,
                    'billable_id' => $user->id,
                    'billable_type' => get_class($user),
                    'type' => 'default',
                    'paddle_id' => 'sub_mock_'.str_replace('-', '', $user->id),
                    'paddle_subscription_id' => 'sub_mock_'.str_replace('-', '', $user->id),
                    'paddle_plan_id' => $priceId,
                    'status' => 'active',
                    'current_period_end' => $currentPeriodEnd,
                    'cancel_at_period_end' => false,
                ]);

                DB::table('subscription_items')->updateOrCreate(
                    ['subscription_id' => $subscription->id],
                    [
                        'product_id' => 'pro_mock',
                        'price_id' => $priceId,
                        'status' => 'active',
                        'quantity' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                if (! $user->plan_expires_at) {
                    $user->forceFill(['plan_expires_at' => $currentPeriodEnd])->save();
                }
            }
        }

        // Load current subscription
        $subscription = Subscription::where('user_id', $user->id)
            ->whereIn('status', ['active', 'past_due', 'trialing'])
            ->orderBy('created_at', 'desc')
            ->first();

        if (! $subscription) {
            $subscription = Subscription::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->first();
        }

        // Retrieve transactions history
        $transactions = $user->transactions()
            ->orderBy('billed_at', 'desc')
            ->get()
            ->map(fn ($tx) => [
                'paddle_id' => $tx->paddle_id,
                'invoice_number' => $tx->invoice_number,
                'status' => $tx->status,
                'total' => $tx->total,
                'tax' => $tx->tax,
                'currency' => $tx->currency,
                'billed_at' => $tx->billed_at->toIso8601String(),
            ]);

        if (! $subscription) {
            $lastTx = $user->transactions()->where('status', 'completed')->orderBy('billed_at', 'desc')->first();
            $isYearly = false;
            if ($lastTx) {
                $totalVal = (float) $lastTx->total;
                if ($totalVal > 30.0) {
                    $isYearly = true;
                }
            }
            $rawAmount = $isYearly ? ($user->plan->price_yearly ?? 0.0) : ($user->plan->price_monthly ?? 0.0);
            $nextPaymentAmount = '$'.number_format($rawAmount, 2);

            $nextBillingDate = null;
            if ($user->plan_expires_at) {
                $nextBillingDate = $user->plan_expires_at->toIso8601String();
            } elseif ($lastTx) {
                $nextBillingDate = $isYearly
                    ? $lastTx->billed_at->addYear()->toIso8601String()
                    : $lastTx->billed_at->addMonth()->toIso8601String();
            }

            return response()->json([
                'subscribed' => ($user->plan_id > 1),
                'plan_name' => $user->plan->display_name ?? 'Free',
                'price' => ($user->plan_id > 1) ? $nextPaymentAmount : null,
                'next_billing_date' => $nextBillingDate,
                'status' => ($user->plan_id > 1) ? 'active' : 'inactive',
                'on_grace_period' => false,
                'ends_at' => null,
                'transactions' => $transactions,
            ]);
        }

        $nextBillingDate = null;
        $nextPaymentAmount = null;

        try {
            $payment = $subscription->nextPayment();
            if ($payment) {
                $nextBillingDate = $payment->date()->toIso8601String();
                $nextPaymentAmount = $payment->amount(); // E.g., "$9.00"
            }
        } catch (\Exception $e) {
            // Ignore API connection issues (e.g. sandbox or offline)
        }

        // Fallback for next billing date
        if (! $nextBillingDate) {
            if ($subscription->ends_at) {
                $nextBillingDate = $subscription->ends_at->toIso8601String();
            } elseif ($subscription->cancel_at) {
                $nextBillingDate = $subscription->cancel_at->toIso8601String();
            } elseif ($subscription->current_period_end) {
                $nextBillingDate = $subscription->current_period_end->toIso8601String();
            } elseif ($user->plan_expires_at) {
                $nextBillingDate = $user->plan_expires_at->toIso8601String();
            } else {
                $lastTx = $user->transactions()->orderBy('billed_at', 'desc')->first();
                if ($lastTx) {
                    $isYearly = false;
                    $priceId = $subscription->items->first()?->price_id ?? $subscription->paddle_plan_id;
                    if ($priceId) {
                        $isYearly = $priceId === config('paddle.prices.creator_yearly') ||
                                    $priceId === config('paddle.prices.pro_yearly') ||
                                    str_contains(strtolower($priceId), 'yearly');
                    } else {
                        $totalVal = (float) $lastTx->total;
                        if ($totalVal > 30.0) {
                            $isYearly = true;
                        }
                    }
                    $nextBillingDate = $isYearly
                        ? $lastTx->billed_at->addYear()->toIso8601String()
                        : $lastTx->billed_at->addMonth()->toIso8601String();
                }
            }
        }

        // Fallback for amount if null or api call failed
        if (! $nextPaymentAmount) {
            $priceId = null;
            if ($subscription) {
                $priceId = $subscription->items->first()?->price_id ?? $subscription->paddle_plan_id;
            }
            $isYearly = false;
            if ($priceId) {
                $priceIdLower = strtolower($priceId);
                $isYearly = $priceId === config('paddle.prices.creator_yearly') ||
                            $priceId === config('paddle.prices.pro_yearly') ||
                            str_contains($priceIdLower, 'yearly');
            } else {
                $lastTx = $user->transactions()->where('status', 'completed')->orderBy('billed_at', 'desc')->first();
                if ($lastTx && (float) $lastTx->total > 30.0) {
                    $isYearly = true;
                }
            }
            $rawAmount = $isYearly ? ($user->plan->price_yearly ?? 0.0) : ($user->plan->price_monthly ?? 0.0);
            $nextPaymentAmount = '$'.number_format($rawAmount, 2);
        }

        $onGracePeriod = $subscription->cancel_at_period_end || $subscription->onGracePeriod();

        return response()->json([
            'subscribed' => true,
            'plan_name' => $user->plan->display_name ?? 'Free',
            'price' => $nextPaymentAmount,
            'next_billing_date' => $nextBillingDate,
            'status' => $subscription->status, // e.g. active, trialing, paused, canceled
            'on_grace_period' => $onGracePeriod,
            'ends_at' => $subscription->ends_at ? $subscription->ends_at->toIso8601String() : ($subscription->cancel_at ? $subscription->cancel_at->toIso8601String() : null),
            'transactions' => $transactions,
        ]);
    }

    /**
     * POST /api/subscription/cancel
     * Cancel the active subscription at the period end.
     */
    public function cancel(Request $request): JsonResponse
    {
        $user = $request->user();

        $subscription = Subscription::where('user_id', $user->id)
            ->whereIn('status', ['active', 'past_due'])
            ->first();

        if (! $subscription) {
            $cashierSub = $user->subscription();
            if ($cashierSub) {
                $subscription = Subscription::find($cashierSub->id);
            }
        }

        if (! $subscription) {
            return response()->json(['message' => 'No active subscription found.'], 400);
        }

        // Guard: reject cancel if cancel_at_period_end is already true
        if ($subscription->cancel_at_period_end) {
            return response()->json([
                'message' => 'Subscription is already scheduled for cancellation.',
                'cancel_at' => $subscription->cancel_at ? $subscription->cancel_at->toIso8601String() : null,
            ], 400);
        }

        $subId = $subscription->paddle_subscription_id ?? $subscription->paddle_id;

        try {
            $response = Http::withHeaders($this->getPaddleHeaders())
                ->delete("{$this->getPaddleBaseUrl()}/subscriptions/{$subId}", [
                    'effective_from' => 'next_billing_period',
                ]);

            if ($response->failed()) {
                Log::error('Paddle cancel error response', [
                    'body' => $response->body(),
                    'status' => $response->status(),
                ]);

                return response()->json(['message' => 'Failed to cancel subscription with billing provider.'], 500);
            }

            $responseData = $response->json();
            $effectiveAt = $responseData['data']['scheduled_change']['effective_at'] ?? null;

            // Update DB record immediately
            $subscription->update([
                'cancel_at_period_end' => true,
                'cancel_at' => $effectiveAt,
            ]);

            return response()->json([
                'message' => 'Cancellation requested successfully. Access continues until period end.',
                'status' => $subscription->status,
                'cancel_at_period_end' => true,
                'cancel_at' => $effectiveAt,
            ]);
        } catch (\Exception $e) {
            Log::error('Paddle cancel exception', ['message' => $e->getMessage()]);

            return response()->json(['message' => 'An unexpected error occurred during cancellation.'], 500);
        }
    }

    /**
     * POST /api/subscription/reactivate
     * Resume a scheduled cancellation immediately.
     */
    public function reactivate(Request $request): JsonResponse
    {
        $user = $request->user();

        $subscription = Subscription::where('user_id', $user->id)
            ->whereIn('status', ['active', 'past_due'])
            ->first();

        if (! $subscription) {
            $cashierSub = $user->subscription();
            if ($cashierSub) {
                $subscription = Subscription::find($cashierSub->id);
            }
        }

        if (! $subscription || ! $subscription->cancel_at_period_end) {
            return response()->json(['message' => 'No cancellable subscription found.'], 400);
        }

        $subId = $subscription->paddle_subscription_id ?? $subscription->paddle_id;

        try {
            $response = Http::withHeaders($this->getPaddleHeaders())
                ->post("{$this->getPaddleBaseUrl()}/subscriptions/{$subId}/resume", [
                    'effective_from' => 'immediately',
                ]);

            if ($response->failed()) {
                Log::error('Paddle resume error response', [
                    'body' => $response->body(),
                    'status' => $response->status(),
                ]);

                return response()->json(['message' => 'Failed to reactivate subscription with billing provider.'], 500);
            }

            // Update DB record
            $subscription->update([
                'cancel_at_period_end' => false,
                'cancel_at' => null,
            ]);

            return response()->json([
                'message' => 'Subscription reactivated successfully.',
                'status' => $subscription->status,
                'cancel_at_period_end' => false,
                'cancel_at' => null,
            ]);
        } catch (\Exception $e) {
            Log::error('Paddle resume exception', ['message' => $e->getMessage()]);

            return response()->json(['message' => 'An unexpected error occurred during reactivation.'], 500);
        }
    }

    /**
     * GET /api/subscription/status
     * Retrieve the current subscription status details.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        $subscription = Subscription::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->first();

        if (! $subscription) {
            $cashierSub = $user->subscription();
            if ($cashierSub) {
                $subscription = Subscription::find($cashierSub->id);
            }
        }

        if (! $subscription) {
            return response()->json([
                'status' => 'free',
                'cancel_at_period_end' => false,
                'cancel_at' => null,
                'current_period_end' => null,
                'ends_at' => null,
            ]);
        }

        return response()->json([
            'status' => $subscription->status,
            'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
            'cancel_at' => $subscription->cancel_at ? $subscription->cancel_at->toIso8601String() : null,
            'current_period_end' => $subscription->current_period_end ? $subscription->current_period_end->toIso8601String() : null,
            'ends_at' => $subscription->ends_at ? $subscription->ends_at->toIso8601String() : null,
        ]);
    }

    /**
     * GET /api/subscription/portal-url
     * Returns a Paddle Customer Portal session URL generated via the Paddle API.
     *
     * Paddle only accepts signed, single-use session links
     * (https://customer-portal.paddle.com/cpl_...). The bare portal domain is not
     * a usable destination, so every failure path returns url => null with a
     * message instead of a dead redirect.
     */
    public function portalUrl(Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            $customerId = $this->resolvePaddleCustomerId($user);

            if (! $customerId) {
                return response()->json([
                    'url' => null,
                    'message' => 'لا يوجد حساب Paddle مرتبط بحسابك.',
                ], 200);
            }

            // Scope the session to the user's live subscriptions so Paddle exposes
            // the payment-method and cancel actions, not just the overview page.
            $subscriptionIds = Subscription::where('user_id', $user->id)
                ->whereIn('status', ['active', 'past_due', 'trialing', 'paused'])
                ->get()
                ->map(fn ($sub) => $sub->paddle_subscription_id ?: $sub->paddle_id)
                ->filter(fn ($id) => $id && str_starts_with($id, 'sub_') && ! str_starts_with($id, 'sub_mock_'))
                ->unique()
                ->values()
                ->all();

            $payload = $subscriptionIds ? ['subscription_ids' => $subscriptionIds] : new \stdClass;

            // POST /customers/{customer_id}/portal-sessions
            $response = Http::withHeaders($this->getPaddleHeaders())
                ->withBody(json_encode($payload), 'application/json')
                ->post("{$this->getPaddleBaseUrl()}/customers/{$customerId}/portal-sessions");

            if ($response->failed()) {
                Log::error('Paddle portal-sessions failed', [
                    'user_id' => $user->id,
                    'customer_id' => $customerId,
                    'sandbox' => (bool) config('paddle.sandbox'),
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return response()->json([
                    'url' => null,
                    'message' => 'تعذّر فتح بوابة الدفع حالياً. يرجى المحاولة لاحقاً.',
                ], 502);
            }

            $urls = $response->json('data.urls') ?? [];
            $portalUrl = $urls['general']['overview'] ?? $urls['overview'] ?? null;

            if (! $portalUrl) {
                Log::error('Paddle portal-sessions returned no usable URL', [
                    'user_id' => $user->id,
                    'customer_id' => $customerId,
                    'body' => $response->body(),
                ]);

                return response()->json([
                    'url' => null,
                    'message' => 'تعذّر فتح بوابة الدفع حالياً. يرجى المحاولة لاحقاً.',
                ], 502);
            }

            return response()->json(['url' => $portalUrl]);
        } catch (\Exception $e) {
            Log::error('Paddle portal URL generation exception', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['url' => null, 'message' => 'فشل تحميل بوابة الدفع'], 500);
        }
    }

    /**
     * Resolve a real Paddle customer id (ctm_...) for the given user.
     *
     * billingInfo() self-heals a missing customer row with a placeholder
     * "ctm_mock_..." id that Paddle will never recognise. When we encounter one
     * of those — or no row at all — look the customer up by email and repair the
     * stored id so the portal keeps working from then on.
     */
    private function resolvePaddleCustomerId($user): ?string
    {
        $storedId = $user->customer?->paddle_id;

        if ($storedId && str_starts_with($storedId, 'ctm_') && ! str_starts_with($storedId, 'ctm_mock_')) {
            return $storedId;
        }

        if (! $user->email) {
            return null;
        }

        $lookup = Http::withHeaders($this->getPaddleHeaders())
            ->get("{$this->getPaddleBaseUrl()}/customers", [
                'email' => $user->email,
                'status' => 'active',
            ]);

        if ($lookup->failed()) {
            Log::error('Paddle customer lookup by email failed', [
                'user_id' => $user->id,
                'sandbox' => (bool) config('paddle.sandbox'),
                'status' => $lookup->status(),
                'body' => $lookup->body(),
            ]);

            return null;
        }

        $realId = $lookup->json('data.0.id');

        if (! $realId) {
            Log::warning('No Paddle customer matches the user email', [
                'user_id' => $user->id,
                'stored_paddle_id' => $storedId,
            ]);

            return null;
        }

        // customers.paddle_id is unique — never clobber a row owned by someone else.
        $ownedByOther = DB::table('customers')
            ->where('paddle_id', $realId)
            ->where(function ($q) use ($user) {
                $q->where('billable_id', '!=', $user->id)
                    ->orWhere('billable_type', '!=', get_class($user));
            })
            ->exists();

        if ($ownedByOther) {
            Log::warning('Paddle customer id already linked to another billable; not rewriting', [
                'user_id' => $user->id,
                'paddle_id' => $realId,
            ]);

            return $realId;
        }

        DB::table('customers')->updateOrInsert(
            [
                'billable_type' => get_class($user),
                'billable_id' => $user->id,
            ],
            [
                'paddle_id' => $realId,
                'name' => $user->name ?? 'Customer',
                'email' => $user->email,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $user->unsetRelation('customer');

        Log::info('Repaired Paddle customer id', [
            'user_id' => $user->id,
            'from' => $storedId,
            'to' => $realId,
        ]);

        return $realId;
    }

    /**
     * POST /api/subscription/sync
     * Client-initiated synchronization of Paddle subscription/transaction data
     */
    public function syncPaddle(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $subscriptionId = $request->input('subscription_id');
        $transactionId = $request->input('transaction_id');

        if (! $subscriptionId && ! $transactionId) {
            return response()->json(['error' => 'Missing subscription_id or transaction_id'], 400);
        }

        $synced = false;

        // 1. Sync Transaction first (to extract potential subscription_id)
        if ($transactionId) {
            try {
                $response = Http::withHeaders($this->getPaddleHeaders())
                    ->get("{$this->getPaddleBaseUrl()}/transactions/{$transactionId}");

                if ($response->successful()) {
                    $txData = $response->json()['data'] ?? null;
                    if ($txData) {
                        $txUserId = $txData['custom_data']['userId'] ?? $txData['custom_data']['user_id'] ?? null;
                        if ($txUserId && $txUserId == $userId) {
                            Subscription::syncTransactionFromPaddleData($txData);
                            $synced = true;

                            // Extract associated subscription ID if we don't have one yet
                            $assocSubId = $txData['subscription_id'] ?? null;
                            if ($assocSubId && ! $subscriptionId) {
                                $subscriptionId = $assocSubId;
                            }
                        } else {
                            Log::warning("Paddle sync: Transaction user mismatch. Tx user: {$txUserId}, Auth user: {$userId}");
                        }
                    }
                } else {
                    Log::error('Paddle sync transaction API failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Paddle sync transaction exception: '.$e->getMessage());
            }
        }

        // 2. Sync Subscription next
        if ($subscriptionId) {
            try {
                $response = Http::withHeaders($this->getPaddleHeaders())
                    ->get("{$this->getPaddleBaseUrl()}/subscriptions/{$subscriptionId}");

                if ($response->successful()) {
                    $subData = $response->json()['data'] ?? null;
                    if ($subData) {
                        $subUserId = $subData['custom_data']['userId'] ?? $subData['custom_data']['user_id'] ?? null;
                        if ($subUserId && $subUserId == $userId) {
                            Subscription::syncFromPaddleData($subData);
                            $synced = true;
                        } else {
                            Log::warning("Paddle sync: User mismatch. Sub user: {$subUserId}, Auth user: {$userId}");
                        }
                    }
                } else {
                    Log::error('Paddle sync subscription API failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Paddle sync subscription exception: '.$e->getMessage());
            }
        }

        if ($synced) {
            return response()->json(['success' => true, 'message' => 'Subscription synchronized successfully.']);
        }

        return response()->json(['success' => false, 'message' => 'Synchronization failed or not applicable.'], 400);
    }

    /**
     * Helpers for direct Paddle API requests
     */
    private function getPaddleBaseUrl(): string
    {
        return config('paddle.sandbox', true) ? 'https://sandbox-api.paddle.com' : 'https://api.paddle.com';
    }

    private function getPaddleHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.config('paddle.api_key'),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }
}
