<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * POST /webhook/paddle
     * Handles Paddle webhooks securely and idempotently
     */
    public function handlePaddle(Request $request): JsonResponse
    {
        // 1. Verify Paddle signature BEFORE anything else
        if (! $this->verifyPaddleSignature($request)) {
            return response()->json(['error' => 'Invalid signature.'], 403);
        }

        $eventId = $request->input('event_id');
        $eventType = $request->input('event_type');
        $payload = $request->all();

        if (empty($eventId)) {
            Log::error('Paddle Webhook missing event ID', ['payload' => $payload]);

            return response()->json(['error' => 'Missing event ID.'], 400);
        }

        // 2. Check if event is already processed (Idempotency check)
        $existingLog = DB::table('webhook_logs')->where('paddle_event_id', $eventId)->first();
        if ($existingLog) {
            Log::info("Paddle webhook already processed (Idempotency): {$eventId}");

            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        // 3. Log webhook arrival in webhook_logs
        $logId = DB::table('webhook_logs')->insertGetId([
            'event_type' => $eventType,
            'paddle_event_id' => $eventId,
            'payload' => json_encode($payload),
            'received_at' => now(),
            'processed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $data = $request->input('data');
            if (! $data) {
                throw new \Exception('Paddle webhook data is null.');
            }

            match ($eventType) {
                'subscription.created', 'subscription.updated' => $this->onUpdated($data),
                'subscription.canceled', 'subscription.cancelled' => $this->onCancelled($data),
                'transaction.completed' => $this->onTransactionCompleted($data),
                default => Log::info("Paddle Webhook event type ignored: {$eventType}"),
            };

            // 4. Mark processed_at on success
            DB::table('webhook_logs')->where('id', $logId)->update([
                'processed_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            Log::error('Paddle Webhook processing failed', [
                'event_id' => $eventId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Webhook processing failed.'], 500);
        }
    }

    /**
     * Verifies Paddle-Signature using HMAC-SHA256
     */
    private function verifyPaddleSignature(Request $request): bool
    {
        $signature = $request->header('Paddle-Signature');
        if (empty($signature)) {
            Log::warning('Paddle Webhook Signature header is missing.');

            return false;
        }

        $secret = config('paddle.webhook_secret');
        if (empty($secret)) {
            if (app()->environment('local', 'testing')) {
                Log::warning('PADDLE_WEBHOOK_SECRET is empty. Bypassing signature verification in local/testing environment.');

                return true;
            }
            Log::error('PADDLE_WEBHOOK_SECRET is empty in non-local/testing environment.');

            return false;
        }

        $timestamp = 0;
        $hashes = [];
        foreach (explode(';', $signature) as $part) {
            if (str_contains($part, '=')) {
                [$key, $value] = explode('=', $part, 2);
                if ($key === 'ts') {
                    $timestamp = (int) $value;
                } elseif ($key === 'h1') {
                    $hashes[] = $value;
                }
            }
        }

        $variance = 300;
        if (abs(time() - $timestamp) > $variance) {
            Log::warning('Paddle Webhook signature timestamp variance exceeded.', [
                'server_time' => time(),
                'signature_time' => $timestamp,
            ]);

            return false;
        }

        $data = $request->getContent();
        $computedHash = hash_hmac('sha256', "{$timestamp}:{$data}", $secret);

        foreach ($hashes as $possibleHash) {
            if (hash_equals($computedHash, $possibleHash)) {
                return true;
            }
        }

        Log::warning('Paddle Webhook signature mismatch.');

        return false;
    }

    private function onCreated(array $data): void
    {
        Log::info('Paddle Webhook: Processing subscription.updated/created', [
            'subscription_id' => $data['id'] ?? null,
            'userId' => $data['custom_data']['userId'] ?? $data['custom_data']['user_id'] ?? null,
            'priceId' => $data['items'][0]['price']['id'] ?? null,
        ]);

        Subscription::syncFromPaddleData($data);
    }

    private function onUpdated(array $data): void
    {
        $this->onCreated($data);
    }

    private function onCancelled(array $data): void
    {
        Log::info('Paddle Webhook: Processing subscription.canceled', [
            'subscription_id' => $data['id'],
            'status' => $data['status'],
        ]);

        $subscription = Subscription::where('paddle_subscription_id', $data['id'])->first();
        if (! $subscription) {
            $subscription = Subscription::where('paddle_id', $data['id'])->first();
        }

        if (! $subscription) {
            throw new \Exception("Subscription {$data['id']} not found in database.");
        }

        $userId = $subscription->user_id;
        $effectiveAt = isset($data['canceled_at']) ? \Carbon\Carbon::parse($data['canceled_at']) : now();

        $subscription->update([
            'status' => 'canceled',
            'ends_at' => $effectiveAt,
        ]);

        $user = User::where('id', $userId)->first();
        if ($user) {
            $user->forceFill([
                'plan_id' => 1,
                'plan_expires_at' => $effectiveAt,
            ])->save();

            Cache::forget('plan_context:'.$userId);
            Cache::forget('plan:'.$userId);
            Log::info("Downgraded user {$userId} to Free plan due to canceled subscription.");
        }
    }

    private function onExpired(array $data): void
    {
        $this->onCancelled($data);
    }

    private function onTransactionCompleted(array $data): void
    {
        Log::info('Paddle Webhook: Processing transaction.completed', [
            'transaction_id' => $data['id'] ?? null,
            'userId' => $data['custom_data']['userId'] ?? $data['custom_data']['user_id'] ?? null,
        ]);

        Subscription::syncTransactionFromPaddleData($data);
    }

    private function mapPaddlePlanId(string $priceId): int
    {
        return Subscription::mapPaddlePlanId($priceId);
    }
}
