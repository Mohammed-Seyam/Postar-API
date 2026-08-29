<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncCanceledSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:sync-canceled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync canceled subscriptions where cancel_at date has passed but status is still active';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting sync-canceled command...');

        // Fetch subscriptions that should be canceled
        $subscriptions = Subscription::where('cancel_at', '<=', now())
            ->where('status', 'active')
            ->where('cancel_at_period_end', true)
            ->get();

        $this->info("Found {$subscriptions->count()} subscriptions to check.");

        foreach ($subscriptions as $subscription) {
            $subId = $subscription->paddle_subscription_id ?? $subscription->paddle_id;
            $this->info("Syncing subscription: {$subId}");

            try {
                $response = Http::withHeaders($this->getPaddleHeaders())
                    ->get("{$this->getPaddleBaseUrl()}/subscriptions/{$subId}");

                if ($response->failed()) {
                    Log::error("Artisan Sync: Failed to fetch Paddle subscription {$subId}", [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    $this->error("Failed to fetch Paddle subscription {$subId}");

                    continue;
                }

                $responseData = $response->json();
                $paddleStatus = $responseData['data']['status'] ?? null;

                if ($paddleStatus === 'canceled') {
                    // Update DB status to canceled
                    $subscription->update([
                        'status' => 'canceled',
                        'ends_at' => $subscription->cancel_at ?? now(),
                    ]);

                    // Downgrade User to Free
                    $user = User::find($subscription->user_id);
                    if ($user) {
                        $user->forceFill([
                            'plan_id' => 1,
                            'plan_expires_at' => null,
                        ])->save();

                        Cache::forget('plan_context:'.$user->id);
                        Cache::forget('plan:'.$user->id);
                    }

                    Log::warning("Artisan Sync Discrepancy: Subscription {$subId} was active in DB but canceled in Paddle. Synced user {$subscription->user_id} to Free plan.");
                    $this->warn("Subscription {$subId} synced to canceled state.");
                } else {
                    $this->info("Subscription {$subId} is still {$paddleStatus} in Paddle.");
                }
            } catch (\Exception $e) {
                Log::error("Artisan Sync: Exception syncing subscription {$subId}", [
                    'message' => $e->getMessage(),
                ]);
                $this->error("Error syncing subscription {$subId}: ".$e->getMessage());
            }
        }

        $this->info('Completed sync-canceled command.');

        return Command::SUCCESS;
    }

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
