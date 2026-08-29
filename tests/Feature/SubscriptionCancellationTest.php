<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubscriptionCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Mock default plans database records if they do not exist
        if (DB::table('plans')->count() === 0) {
            DB::table('plans')->insert([
                ['id' => 1, 'name' => 'free', 'display_name' => 'Free', 'allowed_platforms' => json_encode([])],
                ['id' => 2, 'name' => 'creator', 'display_name' => 'Creator', 'allowed_platforms' => json_encode([])],
                ['id' => 3, 'name' => 'pro', 'display_name' => 'Pro', 'allowed_platforms' => json_encode([])],
            ]);
        }
    }

    /**
     * Test successful subscription cancellation.
     */
    public function test_cancel_subscription_success()
    {
        $user = User::factory()->create(['plan_id' => 2]);
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'billable_id' => $user->id,
            'billable_type' => User::class,
            'type' => 'default',
            'paddle_id' => 'sub_test_123',
            'paddle_subscription_id' => 'sub_test_123',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'current_period_end' => now()->addDays(30),
        ]);

        $effectiveDate = '2026-07-07T00:00:00Z';

        Http::fake([
            'https://sandbox-api.paddle.com/subscriptions/sub_test_123' => Http::response([
                'data' => [
                    'id' => 'sub_test_123',
                    'status' => 'active',
                    'scheduled_change' => [
                        'action' => 'cancel',
                        'effective_at' => $effectiveDate,
                        'resume_at' => null,
                    ],
                    'current_billing_period' => [
                        'ends_at' => $effectiveDate,
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user)->postJson('/api/subscription/cancel');

        $response->assertStatus(200)
            ->assertJsonPath('cancel_at_period_end', true)
            ->assertJsonPath('cancel_at', $effectiveDate);

        $subscription->refresh();
        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertEquals($effectiveDate, $subscription->cancel_at->toIso8601ZuluString());

        // Ensure user's plan is NOT changed immediately (it stays creator = 2)
        $user->refresh();
        $this->assertEquals(2, $user->plan_id);
    }

    /**
     * Test cancellation guard rejects double cancel requests.
     */
    public function test_cancel_subscription_guard_rejects_already_canceled()
    {
        $user = User::factory()->create(['plan_id' => 2]);
        Subscription::create([
            'user_id' => $user->id,
            'billable_id' => $user->id,
            'billable_type' => User::class,
            'type' => 'default',
            'paddle_id' => 'sub_test_123',
            'paddle_subscription_id' => 'sub_test_123',
            'status' => 'active',
            'cancel_at_period_end' => true,
            'cancel_at' => now()->addDays(15),
            'current_period_end' => now()->addDays(15),
        ]);

        $response = $this->actingAs($user)->postJson('/api/subscription/cancel');

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Subscription is already scheduled for cancellation.');
    }

    /**
     * Test successful subscription reactivation.
     */
    public function test_reactivate_subscription_success()
    {
        $user = User::factory()->create(['plan_id' => 2]);
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'billable_id' => $user->id,
            'billable_type' => User::class,
            'type' => 'default',
            'paddle_id' => 'sub_test_123',
            'paddle_subscription_id' => 'sub_test_123',
            'status' => 'active',
            'cancel_at_period_end' => true,
            'cancel_at' => now()->addDays(15),
            'current_period_end' => now()->addDays(15),
        ]);

        Http::fake([
            'https://sandbox-api.paddle.com/subscriptions/sub_test_123/resume' => Http::response([
                'data' => [
                    'id' => 'sub_test_123',
                    'status' => 'active',
                    'scheduled_change' => null,
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user)->postJson('/api/subscription/reactivate');

        $response->assertStatus(200)
            ->assertJsonPath('cancel_at_period_end', false)
            ->assertJsonPath('cancel_at', null);

        $subscription->refresh();
        $this->assertFalse($subscription->cancel_at_period_end);
        $this->assertNull($subscription->cancel_at);

        $user->refresh();
        $this->assertEquals(2, $user->plan_id); // Stays Creator
    }

    /**
     * Test status endpoint returns correct structure.
     */
    public function test_subscription_status_endpoint()
    {
        $user = User::factory()->create(['plan_id' => 3]);
        $currentPeriodEnd = now()->addDays(10)->startOfSecond();
        Subscription::create([
            'user_id' => $user->id,
            'billable_id' => $user->id,
            'billable_type' => User::class,
            'type' => 'default',
            'paddle_id' => 'sub_test_123',
            'paddle_subscription_id' => 'sub_test_123',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'current_period_end' => $currentPeriodEnd,
        ]);

        $response = $this->actingAs($user)->getJson('/api/subscription/status');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'active',
                'cancel_at_period_end' => false,
                'cancel_at' => null,
                'current_period_end' => $currentPeriodEnd->toIso8601String(),
                'ends_at' => null,
            ]);
    }

    /**
     * Test webhook subscription.updated updates cancel_at in database.
     */
    public function test_webhook_updated_schedules_cancel()
    {
        $user = User::factory()->create(['plan_id' => 2]);
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'billable_id' => $user->id,
            'billable_type' => User::class,
            'type' => 'default',
            'paddle_id' => 'sub_test_123',
            'paddle_subscription_id' => 'sub_test_123',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'current_period_end' => now()->addDays(20),
        ]);

        // Mock signature verification bypass in local environment by setting no secret
        putenv('PADDLE_WEBHOOK_SECRET=');

        $effectiveAt = '2026-07-07T00:00:00Z';
        $payload = [
            'event_id' => 'evt_updated_999',
            'event_type' => 'subscription.updated',
            'data' => [
                'id' => 'sub_test_123',
                'status' => 'active',
                'custom_data' => [
                    'userId' => $user->id,
                ],
                'items' => [
                    [
                        'price' => [
                            'id' => env('PADDLE_PRICE_CREATOR_MONTHLY') ?: 'pri_creator_123',
                        ],
                    ],
                ],
                'scheduled_change' => [
                    'action' => 'cancel',
                    'effective_at' => $effectiveAt,
                ],
                'current_billing_period' => [
                    'ends_at' => $effectiveAt,
                ],
            ],
        ];

        // Paddle-Signature header to trigger parsing logic
        $response = $this->withHeaders([
            'Paddle-Signature' => 'ts='.time().';h1=test_sig_hash',
        ])->postJson('/api/webhook/paddle', $payload);

        $response->assertStatus(200);

        $subscription->refresh();
        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertEquals($effectiveAt, $subscription->cancel_at->toIso8601ZuluString());

        // Assert webhook log is created
        $this->assertDatabaseHas('webhook_logs', [
            'paddle_event_id' => 'evt_updated_999',
            'event_type' => 'subscription.updated',
        ]);
    }

    /**
     * Test webhook subscription.canceled downgrades user plan.
     */
    public function test_webhook_canceled_downgrades_user_to_free()
    {
        $user = User::factory()->create(['plan_id' => 3]);
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'billable_id' => $user->id,
            'billable_type' => User::class,
            'type' => 'default',
            'paddle_id' => 'sub_test_123',
            'paddle_subscription_id' => 'sub_test_123',
            'status' => 'active',
            'cancel_at_period_end' => true,
            'cancel_at' => now()->subMinutes(10),
            'current_period_end' => now()->subMinutes(10),
        ]);

        putenv('PADDLE_WEBHOOK_SECRET=');

        $canceledAt = '2026-06-08T00:00:00Z';
        $payload = [
            'event_id' => 'evt_canceled_888',
            'event_type' => 'subscription.canceled',
            'data' => [
                'id' => 'sub_test_123',
                'status' => 'canceled',
                'canceled_at' => $canceledAt,
            ],
        ];

        $response = $this->withHeaders([
            'Paddle-Signature' => 'ts='.time().';h1=test_sig_hash',
        ])->postJson('/api/webhook/paddle', $payload);

        $response->assertStatus(200);

        $subscription->refresh();
        $this->assertEquals('canceled', $subscription->status);
        $this->assertEquals($canceledAt, $subscription->ends_at->toIso8601ZuluString());

        $user->refresh();
        $this->assertEquals(1, $user->plan_id); // Reverted to Free!
    }

    /**
     * Test webhook idempotency.
     */
    public function test_webhook_idempotency_ignores_already_processed_events()
    {
        $user = User::factory()->create(['plan_id' => 2]);
        Subscription::create([
            'user_id' => $user->id,
            'billable_id' => $user->id,
            'billable_type' => User::class,
            'type' => 'default',
            'paddle_id' => 'sub_test_123',
            'paddle_subscription_id' => 'sub_test_123',
            'status' => 'active',
        ]);

        putenv('PADDLE_WEBHOOK_SECRET=');

        $payload = [
            'event_id' => 'evt_same_111',
            'event_type' => 'subscription.updated',
            'data' => [
                'id' => 'sub_test_123',
                'status' => 'active',
                'custom_data' => ['userId' => $user->id],
                'items' => [['price' => ['id' => 'pri_creator']]],
            ],
        ];

        // Send 1st time
        $response1 = $this->withHeaders([
            'Paddle-Signature' => 'ts='.time().';h1=test_sig_hash',
        ])->postJson('/api/webhook/paddle', $payload);
        $response1->assertStatus(200);

        // Send 2nd time
        $response2 = $this->withHeaders([
            'Paddle-Signature' => 'ts='.time().';h1=test_sig_hash',
        ])->postJson('/api/webhook/paddle', $payload);

        $response2->assertStatus(200)
            ->assertJsonPath('duplicate', true);

        // Assert database log contains exactly 1 row
        $logsCount = DB::table('webhook_logs')->where('paddle_event_id', 'evt_same_111')->count();
        $this->assertEquals(1, $logsCount);
    }

    /**
     * Test Artisan sync command correctly synchronizes out-of-sync cancellations.
     */
    public function test_artisan_sync_canceled_command()
    {
        $user = User::factory()->create(['plan_id' => 3]);
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'billable_id' => $user->id,
            'billable_type' => User::class,
            'type' => 'default',
            'paddle_id' => 'sub_test_456',
            'paddle_subscription_id' => 'sub_test_456',
            'status' => 'active',
            'cancel_at_period_end' => true,
            'cancel_at' => now()->subDays(1), // Passed!
            'current_period_end' => now()->subDays(1),
        ]);

        Http::fake([
            'https://sandbox-api.paddle.com/subscriptions/sub_test_456' => Http::response([
                'data' => [
                    'id' => 'sub_test_456',
                    'status' => 'canceled',
                    'canceled_at' => now()->subDays(1)->toIso8601String(),
                ],
            ], 200),
        ]);

        // Run Artisan command
        Artisan::call('subscriptions:sync-canceled');

        $subscription->refresh();
        $this->assertEquals('canceled', $subscription->status);

        $user->refresh();
        $this->assertEquals(1, $user->plan_id); // Reverted to Free!
    }
}
