<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TikTokAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_tiktok_redirect_generates_url_with_pkce(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/auth/tiktok/redirect');

        $response->assertStatus(200);
        $response->assertJsonStructure(['url']);

        $url = $response->json('url');
        $this->assertStringStartsWith('https://www.tiktok.com/v2/auth/authorize/?', $url);

        $parsedUrl = parse_url($url);
        parse_str($parsedUrl['query'] ?? '', $queryParams);
        $this->assertStringContainsString('video.list', $queryParams['scope'] ?? '');

        $this->assertArrayHasKey('code_challenge', $queryParams);
        $this->assertArrayHasKey('code_challenge_method', $queryParams);
        $this->assertEquals('S256', $queryParams['code_challenge_method']);
        $this->assertArrayHasKey('state', $queryParams);

        // Decrypt the state to check code_verifier
        $stateData = Crypt::decrypt($queryParams['state']);
        $this->assertEquals($user->id, $stateData['user_id']);
        $this->assertArrayHasKey('code_verifier', $stateData);
        $this->assertNotEmpty($stateData['code_verifier']);

        // Check code_challenge matches code_verifier
        $codeVerifier = $stateData['code_verifier'];
        $rawHash = hash('sha256', $codeVerifier, true);
        $expectedChallenge = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($rawHash));
        $this->assertEquals($expectedChallenge, $queryParams['code_challenge']);
    }

    public function test_tiktok_callback_exchanges_code_using_code_verifier(): void
    {
        $user = User::factory()->create();

        $codeVerifier = 'test-code-verifier-string-12345';
        $stateData = [
            'user_id' => $user->id,
            'ts' => time(),
            'nonce' => 'random-nonce',
            'code_verifier' => $codeVerifier,
        ];
        $signedState = Crypt::encrypt($stateData);

        Http::fake([
            'https://open.tiktokapis.com/v2/oauth/token/' => function (\Illuminate\Http\Client\Request $request) use ($codeVerifier) {
                $data = [];
                parse_str($request->body(), $data);

                if (($data['code_verifier'] ?? null) === $codeVerifier && ($data['code'] ?? null) === 'mock-auth-code') {
                    return Http::response([
                        'open_id' => 'tiktok-open-id-123',
                        'access_token' => 'mock-access-token',
                        'refresh_token' => 'mock-refresh-token',
                        'expires_in' => 86400,
                        'refresh_expires_in' => 2592000,
                        'scope' => 'user.info.basic,user.info.profile,video.publish,video.list',
                    ], 200);
                }

                return Http::response(['error' => ['code' => 'invalid_grant', 'message' => 'Invalid PKCE verifier']], 400);
            },
            'https://open.tiktokapis.com/v2/user/info/*' => Http::response([
                'data' => [
                    'user' => [
                        'open_id' => 'tiktok-open-id-123',
                        'display_name' => 'TikTok User',
                        'avatar_url' => 'https://avatar.url/123.png',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->get('/api/auth/tiktok/callback?'.http_build_query([
            'code' => 'mock-auth-code',
            'state' => $signedState,
        ]));

        $response->assertStatus(302);
        $response->assertRedirect(config('app.frontend_url') . '/app/accounts?connected=tiktok');

        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'platform_user_id' => 'tiktok-open-id-123',
            'account_name' => 'TikTok User',
        ]);

        $account = \App\Models\SocialAccount::where('user_id', $user->id)
            ->where('platform', 'tiktok')
            ->firstOrFail();

        $this->assertEquals('mock-access-token', $account->access_token);
        $this->assertEquals('mock-refresh-token', $account->refresh_token);
    }

    public function test_tiktok_refresh_token_handles_flat_error_format(): void
    {
        $user = User::factory()->create();
        $account = \App\Models\SocialAccount::factory()->create([
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'refresh_token' => 'expired-refresh-token',
        ]);

        Http::fake([
            'https://open.tiktokapis.com/v2/oauth/token/' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'refresh_token is expired',
            ], 200),
        ]);

        /** @var \App\Services\Platforms\TikTokService $tiktok */
        $tiktok = app(\App\Services\Platforms\TikTokService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TikTok token refresh failed: refresh_token is expired');

        $tiktok->refreshToken($account);
    }
}
