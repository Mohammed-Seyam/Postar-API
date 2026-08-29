<?php

namespace Tests\Feature;

use App\Models\WaitlistSignup;
use App\Jobs\SendWaitlistConfirmationJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaitlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_creates_a_record_and_queues_job()
    {
        Queue::fake();
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $response = $this->postJson('/api/waitlist', [
            'email' => 'test@example.com',
            'cf-turnstile-response' => 'dummy-token',
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure(['message', 'referral_code']);

        $this->assertDatabaseHas('waitlist_signups', [
            'email' => 'test@example.com',
        ]);

        Queue::assertPushed(SendWaitlistConfirmationJob::class);
    }

    public function test_duplicate_email_is_rejected()
    {
        WaitlistSignup::factory()->create(['email' => 'test@example.com']);

        $response = $this->postJson('/api/waitlist', [
            'email' => 'test@example.com',
            'cf-turnstile-response' => 'dummy-token',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors('email');
    }

    public function test_confirming_a_signup_works()
    {
        $signup = WaitlistSignup::factory()->create([
            'confirmation_token' => 'my-token',
            'confirmed_at' => null,
        ]);

        $response = $this->get('/api/waitlist/confirm/my-token');

        $response->assertRedirect();
        
        $this->assertDatabaseHas('waitlist_signups', [
            'id' => $signup->id,
        ]);
        
        $this->assertNotNull($signup->fresh()->confirmed_at);
    }

    public function test_position_calculation_works()
    {
        // 3 signups
        $signup1 = WaitlistSignup::factory()->create(['confirmed_at' => now()->subDays(3), 'created_at' => now()->subDays(3)]);
        $signup2 = WaitlistSignup::factory()->create(['confirmed_at' => now()->subDays(2), 'created_at' => now()->subDays(2)]);
        $signup3 = WaitlistSignup::factory()->create(['confirmed_at' => now()->subDays(1), 'created_at' => now()->subDays(1)]);
        
        // Position of signup 3 should be 3
        $this->assertEquals(3, $signup3->calculatePosition());
        
        // Give signup3 a referral
        WaitlistSignup::factory()->create([
            'referred_by' => $signup3->referral_code,
            'confirmed_at' => now(),
        ]);

        config(['services.waitlist.referral_boost' => 10]);

        // Position of signup 3 should now be max(1, 3 - 10) = 1
        $this->assertEquals(1, $signup3->fresh()->calculatePosition());
    }

    public function test_status_endpoint_works_with_status_token()
    {
        $signup = WaitlistSignup::factory()->create([
            'confirmed_at' => now(),
            'status_token' => 'secure-status-token-123'
        ]);

        $response = $this->getJson('/api/waitlist/status/secure-status-token-123');

        $response->assertStatus(200)
                 ->assertJsonStructure(['position', 'referral_count', 'referral_link']);
    }

    public function test_status_endpoint_fails_with_public_referral_code()
    {
        $signup = WaitlistSignup::factory()->create([
            'confirmed_at' => now(),
            'referral_code' => 'public-code'
        ]);

        $response = $this->getJson('/api/waitlist/status/public-code');

        $response->assertStatus(404);
    }
}
