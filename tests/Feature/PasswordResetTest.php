<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::clear();
    }

    /**
     * Test 1: Submitting unregistered email returns the exact same 200 response as registered.
     */
    public function test_unregistered_email_returns_same_response_as_registered(): void
    {
        // 1. Unregistered email
        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'If this email exists, you will receive a reset link shortly',
        ]);

        // 2. Registered email
        $user = User::factory()->create([
            'email' => 'registered@example.com',
        ]);

        $response2 = $this->postJson('/api/auth/forgot-password', [
            'email' => 'registered@example.com',
        ]);

        $response2->assertStatus(200);
        $response2->assertJson([
            'message' => 'If this email exists, you will receive a reset link shortly',
        ]);
    }

    /**
     * Test 2: Submitting the same email 4 times in 15 minutes returns 429.
     */
    public function test_submitting_email_4_times_returns_429(): void
    {
        $email = 'test@example.com';

        // First 3 requests should be fine
        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/auth/forgot-password', [
                'email' => $email,
            ]);
            $response->assertStatus(200);
        }

        // 4th request should return 429
        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => $email,
        ]);
        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
    }

    /**
     * Test 3: Submitting from the same IP 6 times in 15 minutes returns 429.
     */
    public function test_submitting_from_ip_6_times_returns_429(): void
    {
        // First 5 requests should pass (using different emails to not trigger the email-based rate limiter)
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/auth/forgot-password', [
                'email' => "user{$i}@example.com",
            ]);
            $response->assertStatus(200);
        }

        // 6th request should return 429
        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'another@example.com',
        ]);
        $response->assertStatus(429);
    }

    /**
     * Test 4: Database stores only the SHA-256 hash of the token, not the raw token.
     */
    public function test_database_stores_token_hash_not_raw(): void
    {
        $user = User::factory()->create([
            'email' => 'registered@example.com',
        ]);

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'registered@example.com',
        ]);

        // Verify database entry
        $tokenRow = DB::table('password_reset_tokens')->where('user_id', $user->id)->first();
        $this->assertNotNull($tokenRow);

        Mail::assertSent(\App\Mail\PasswordResetMail::class, function ($mail) use ($tokenRow) {
            $url = $mail->resetUrl;
            $parts = explode('/', $url);
            $rawToken = end($parts);

            // Check that database has SHA-256 hash, not the raw token
            $this->assertNotEquals($rawToken, $tokenRow->token);
            $this->assertEquals(hash('sha256', $rawToken), $tokenRow->token);

            return true;
        });
    }

    /**
     * Test 5: Passwords under 8 characters are rejected server-side.
     */
    public function test_passwords_under_8_characters_are_rejected(): void
    {
        $user = User::factory()->create();

        // Generate a valid session key
        $sessionKey = 'test-session-key';
        Cache::put("reset_session_{$sessionKey}", [
            'user_id' => $user->id,
            'ip' => '127.0.0.1',
        ], now()->addMinutes(5));

        $response = $this->postJson('/api/auth/reset-password', [
            'session_key' => $sessionKey,
            'password' => 'Short1!', // 7 characters, has mix/number/symbol but short
            'password_confirmation' => 'Short1!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }

    /**
     * Test 6: A previously breached password is rejected (HaveIBeenPwned API check).
     */
    public function test_breached_password_is_rejected(): void
    {
        $password = 'Password123456!';
        $sha1 = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        // Mock HaveIBeenPwned API to simulate a breached password match
        Http::fake([
            "https://api.pwnedpasswords.com/range/{$prefix}" => Http::response("{$suffix}:10", 200),
        ]);

        $user = User::factory()->create();

        $sessionKey = 'test-session-key';
        Cache::put("reset_session_{$sessionKey}", [
            'user_id' => $user->id,
            'ip' => '127.0.0.1',
        ], now()->addMinutes(5));

        $response = $this->postJson('/api/auth/reset-password', [
            'session_key' => $sessionKey,
            'password' => $password,
            'password_confirmation' => $password,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('leak', $response->json('errors.password.0'));
    }

    /**
     * Test 7: After password reset, all active sessions and Sanctum tokens are terminated.
     */
    public function test_reset_terminates_all_active_sessions(): void
    {
        // Mock HaveIBeenPwned API to simulate safe password
        Http::fake([
            'https://api.pwnedpasswords.com/*' => Http::response('NOT_FOUND', 200),
        ]);

        $user = User::factory()->create();

        // Add fake Sanctum tokens
        $user->createToken('token1');
        $user->createToken('token2');
        $this->assertEquals(2, $user->tokens()->count());

        // Add fake database sessions
        DB::table('sessions')->insert([
            ['id' => 'sess1', 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Mozilla', 'payload' => 'abc', 'last_activity' => time()],
            ['id' => 'sess2', 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Mozilla', 'payload' => 'xyz', 'last_activity' => time()],
        ]);

        // Create reset session
        $sessionKey = 'test-session-key';
        Cache::put("reset_session_{$sessionKey}", [
            'user_id' => $user->id,
            'ip' => '127.0.0.1',
        ], now()->addMinutes(5));

        $response = $this->postJson('/api/auth/reset-password', [
            'session_key' => $sessionKey,
            'password' => 'SecurePassword2026!',
            'password_confirmation' => 'SecurePassword2026!',
        ]);

        $response->assertStatus(200);

        // Assert all Sanctum tokens are deleted
        $this->assertEquals(0, $user->tokens()->count());

        // Assert database sessions for user are deleted
        $this->assertEquals(0, DB::table('sessions')->where('user_id', $user->id)->count());

        // Assert confirmation mail was sent
        Mail::assertSent(\App\Mail\PasswordResetConfirmationMail::class);
    }
}
