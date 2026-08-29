<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Paddle\Subscription as CashierSubscription;

class Subscription extends CashierSubscription
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'billable_id',
        'billable_type',
        'type',
        'paddle_id',
        'status',
        'trial_ends_at',
        'paused_at',
        'ends_at',
        // Custom columns
        'user_id',
        'paddle_subscription_id',
        'paddle_plan_id',
        'cancel_at_period_end',
        'cancel_at',
        'current_period_end',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'trial_ends_at' => 'datetime',
        'paused_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'cancel_at' => 'datetime',
        'current_period_end' => 'datetime',
    ];

    /**
     * Get the user that owns the subscription.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Maps Paddle price ID to user plan ID (1: Free, 2: Creator, 3: Pro)
     */
    public static function mapPaddlePlanId(string $priceId): int
    {
        $priceIdLower = strtolower($priceId);

        // 1. Exact matches from config
        if ($priceId === config('paddle.prices.creator_monthly') || $priceId === config('paddle.prices.creator_yearly')) {
            return 2;
        }
        if ($priceId === config('paddle.prices.pro_monthly') || $priceId === config('paddle.prices.pro_yearly')) {
            return 3;
        }

        // 2. Substring fallbacks for local mock testing / simulator
        if (str_contains($priceIdLower, 'creator')) {
            return 2;
        }
        if (str_contains($priceIdLower, 'pro')) {
            return 3;
        }

        return 1;
    }

    /**
     * Synchronize a subscription record from Paddle data array
     */
    public static function syncFromPaddleData(array $data): void
    {
        $userId = $data['custom_data']['userId'] ?? $data['custom_data']['user_id'] ?? null;
        $priceId = $data['items'][0]['price']['id'] ?? '';
        $planId = self::mapPaddlePlanId($priceId);

        if (! $userId) {
            $existingSub = self::where('paddle_subscription_id', $data['id'])->first();
            if ($existingSub) {
                $userId = $existingSub->user_id;
            }
        }

        if (! $userId) {
            throw new \Exception("User ID not found in custom_data for subscription: {$data['id']}");
        }

        $user = User::where('id', $userId)->first();
        if (! $user) {
            throw new \Exception("User with ID {$userId} not found.");
        }

        // Sync Customer record if customer_id is provided
        $customerId = $data['customer_id'] ?? $data['customer']['id'] ?? null;
        if ($customerId) {
            DB::table('customers')->updateOrInsert(
                [
                    'billable_type' => User::class,
                    'billable_id' => $user->id,
                ],
                [
                    'paddle_id' => $customerId,
                    'name' => $user->name ?? 'Customer',
                    'email' => $user->email,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $periodEnd = isset($data['current_billing_period']['ends_at']) ? Carbon::parse($data['current_billing_period']['ends_at']) : null;

        if ($data['status'] === 'active' || $data['status'] === 'past_due' || $data['status'] === 'trialing') {
            $user->forceFill([
                'plan_id' => $planId,
                'plan_expires_at' => $periodEnd,
            ])->save();
        }

        $cancelAtPeriodEnd = false;
        $cancelAt = null;
        if (isset($data['scheduled_change']) && ($data['scheduled_change']['action'] ?? null) === 'cancel') {
            $cancelAtPeriodEnd = true;
            $cancelAt = Carbon::parse($data['scheduled_change']['effective_at']);
        }

        $subRecord = self::updateOrCreate(
            ['paddle_subscription_id' => $data['id']],
            [
                'user_id' => $userId,
                'billable_id' => $userId,
                'billable_type' => User::class,
                'type' => 'default',
                'paddle_id' => $data['id'],
                'paddle_plan_id' => $priceId,
                'status' => $data['status'],
                'cancel_at_period_end' => $cancelAtPeriodEnd,
                'cancel_at' => $cancelAt,
                'current_period_end' => $periodEnd,
                'trial_ends_at' => isset($data['trial_period']['ends_at']) ? Carbon::parse($data['trial_period']['ends_at']) : null,
                'ends_at' => ($data['status'] === 'canceled' || $data['status'] === 'expired') ? now() : null,
            ]
        );

        if ($subRecord) {
            DB::table('subscription_items')->updateOrInsert(
                ['subscription_id' => $subRecord->id],
                [
                    'product_id' => $data['items'][0]['product']['id'] ?? 'pro_mock',
                    'price_id' => $priceId,
                    'status' => $data['status'] ?? 'active',
                    'quantity' => $data['items'][0]['quantity'] ?? 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        Cache::forget('plan_context:'.$userId);
        Cache::forget('plan:'.$userId);

        Log::info("Successfully synced subscription {$data['id']} for user {$userId}. Mapped plan: {$planId}");
    }

    /**
     * Synchronize a transaction record from Paddle data array
     */
    public static function syncTransactionFromPaddleData(array $data): void
    {
        $userId = $data['custom_data']['userId'] ?? $data['custom_data']['user_id'] ?? null;
        if (! $userId) {
            Log::warning('Transaction sync: No userId found in custom_data', ['transaction_id' => $data['id']]);

            return;
        }

        $user = User::where('id', $userId)->first();
        if (! $user) {
            Log::warning("Transaction sync: User with ID {$userId} not found.", ['transaction_id' => $data['id']]);

            return;
        }

        // Sync Customer record if customer_id is provided
        $customerId = $data['customer_id'] ?? $data['customer']['id'] ?? null;
        if ($customerId) {
            DB::table('customers')->updateOrInsert(
                [
                    'billable_type' => get_class($user),
                    'billable_id' => $user->id,
                ],
                [
                    'paddle_id' => $customerId,
                    'name' => $user->name ?? 'Customer',
                    'email' => $user->email,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $totalRaw = $data['details']['totals']['total'] ?? $data['totals']['total'] ?? '0';
        $total = is_numeric($totalRaw) ? number_format($totalRaw / 100, 2) : $totalRaw;

        $taxRaw = $data['details']['totals']['tax'] ?? $data['totals']['tax'] ?? '0';
        $tax = is_numeric($taxRaw) ? number_format($taxRaw / 100, 2) : $taxRaw;

        $status = $data['status'] ?? 'completed';

        DB::table('transactions')->updateOrInsert(
            ['paddle_id' => $data['id']],
            [
                'billable_type' => get_class($user),
                'billable_id' => $user->id,
                'paddle_subscription_id' => $data['subscription_id'] ?? null,
                'invoice_number' => $data['invoice_number'] ?? null,
                'status' => $status,
                'total' => $total,
                'tax' => $tax,
                'currency' => $data['currency_code'] ?? 'USD',
                'billed_at' => isset($data['billed_at']) ? Carbon::parse($data['billed_at']) : now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        if ($status === 'completed' || $status === 'paid' || $status === 'billed') {
            $priceId = $data['items'][0]['price']['id'] ?? '';
            if ($priceId) {
                $planId = self::mapPaddlePlanId($priceId);
                if ($planId > 1 && $user->plan_id < $planId) {
                    $isYearly = $priceId === config('paddle.prices.creator_yearly') || 
                                $priceId === config('paddle.prices.pro_yearly') ||
                                str_contains(strtolower($priceId), 'yearly');
                    
                    $expiresAt = $isYearly ? now()->addYear() : now()->addMonth();
                    
                    $user->forceFill([
                        'plan_id' => $planId,
                        'plan_expires_at' => $user->plan_expires_at && $user->plan_expires_at > now() ? $user->plan_expires_at : $expiresAt,
                    ])->save();

                    Cache::forget('plan_context:'.$userId);
                    Cache::forget('plan:'.$userId);
                    Log::info("Transaction sync: Upgraded user {$userId} to plan {$planId} from transaction {$data['id']}");
                }
            }
        }

        Log::info("Successfully synced transaction {$data['id']} for user {$userId}. Total: {$total}");
    }
}
