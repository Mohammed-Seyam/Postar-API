<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubscriptionPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        if (DB::table('plans')->count() === 0) {
            DB::table('plans')->insert([
                ['id' => 1, 'name' => 'free', 'display_name' => 'Free', 'allowed_platforms' => json_encode([])],
                ['id' => 2, 'name' => 'creator', 'display_name' => 'Creator', 'allowed_platforms' => json_encode([])],
                ['id' => 3, 'name' => 'pro', 'display_name' => 'Pro', 'allowed_platforms' => json_encode([])],
            ]);
        }
    }

    private function linkCustomer(User $user, string $paddleId): void
    {
        DB::table('customers')->insert([
            'billable_type' => User::class,
            'billable_id' => $user->id,
            'paddle_id' => $paddleId,
            'name' => $user->name ?? 'Customer',
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function portalSessionResponse(string $cpl = 'cpl_live_abc123'): array
    {
        return [
            'data' => [
                'id' => 'cpls_01',
                'customer_id' => 'ctm_real_123',
                'urls' => [
                    'general' => ['overview' => "https://customer-portal.paddle.com/{$cpl}"],
                    'subscriptions' => [],
                ],
            ],
        ];
    }

    public function test_portal_returns_signed_session_url_for_real_customer()
    {
        $user = User::factory()->create(['plan_id' => 2]);
        $this->linkCustomer($user, 'ctm_real_123');

        Subscription::create([
            'user_id' => $user->id,
            'billable_id' => $user->id,
            'billable_type' => User::class,
            'type' => 'default',
            'paddle_id' => 'sub_real_1',
            'paddle_subscription_id' => 'sub_real_1',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'current_period_end' => now()->addDays(30),
        ]);

        Http::fake([
            'https://sandbox-api.paddle.com/customers/ctm_real_123/portal-sessions' => Http::response(
                $this->portalSessionResponse()
            ),
        ]);

        $response = $this->actingAs($user)->getJson('/api/subscription/portal-url');

        $response->assertOk()
            ->assertJson(['url' => 'https://customer-portal.paddle.com/cpl_live_abc123']);

        // The live subscription must be attached so Paddle exposes card management.
        Http::assertSent(function ($request) {
            return $request->url() === 'https://sandbox-api.paddle.com/customers/ctm_real_123/portal-sessions'
                && ($request->data()['subscription_ids'] ?? null) === ['sub_real_1'];
        });
    }

    public function test_portal_repairs_mock_customer_id_via_email_lookup()
    {
        $user = User::factory()->create(['plan_id' => 2]);
        $mockId = 'ctm_mock_'.str_replace('-', '', $user->id);
        $this->linkCustomer($user, $mockId);

        Http::fake([
            'https://sandbox-api.paddle.com/customers?*' => Http::response([
                'data' => [['id' => 'ctm_real_123', 'email' => $user->email]],
            ]),
            'https://sandbox-api.paddle.com/customers/ctm_real_123/portal-sessions' => Http::response(
                $this->portalSessionResponse()
            ),
        ]);

        $response = $this->actingAs($user)->getJson('/api/subscription/portal-url');

        $response->assertOk()
            ->assertJson(['url' => 'https://customer-portal.paddle.com/cpl_live_abc123']);

        $this->assertDatabaseHas('customers', [
            'billable_id' => $user->id,
            'paddle_id' => 'ctm_real_123',
        ]);
        $this->assertDatabaseMissing('customers', ['paddle_id' => $mockId]);
    }

    public function test_portal_never_returns_bare_paddle_domain_when_session_fails()
    {
        $user = User::factory()->create(['plan_id' => 2]);
        $this->linkCustomer($user, 'ctm_real_123');

        Http::fake([
            'https://sandbox-api.paddle.com/customers/ctm_real_123/portal-sessions' => Http::response(
                ['error' => ['code' => 'entity_not_found']], 404
            ),
        ]);

        $response = $this->actingAs($user)->getJson('/api/subscription/portal-url');

        $response->assertStatus(502)->assertJson(['url' => null]);
        $this->assertStringNotContainsString('customer.paddle.com', $response->getContent());
    }

    public function test_portal_reports_missing_link_when_no_paddle_customer_exists()
    {
        $user = User::factory()->create(['plan_id' => 1]);

        Http::fake([
            'https://sandbox-api.paddle.com/customers?*' => Http::response(['data' => []]),
        ]);

        $response = $this->actingAs($user)->getJson('/api/subscription/portal-url');

        $response->assertOk()->assertJson(['url' => null]);
        $this->assertNotEmpty($response->json('message'));
    }
}
