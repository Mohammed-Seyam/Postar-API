<?php

namespace Tests\Feature;

use App\Jobs\SchedulePostJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Platforms\PlatformCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class InstagramIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_SECRET = 'test-instagram-app-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.instagram.app_id' => '2172069310259895',
            'services.instagram.app_secret' => self::TEST_SECRET,
            'services.instagram.redirect_uri' => 'https://api.postar.blog/api/auth/instagram/callback',
            'services.instagram.api_version' => 'v26.0',
        ]);
    }

    // ── OAuth ────────────────────────────────────────────────────────────────

    private function startOAuth(User $user): string
    {
        $url = $this->actingAs($user)->getJson('/api/auth/instagram/redirect')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    public function test_redirect_builds_instagram_authorize_url_with_signed_state(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/auth/instagram/redirect');

        $response->assertOk();
        $url = $response->json('url');
        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('2172069310259895', $query['client_id']);
        $this->assertSame('https://api.postar.blog/api/auth/instagram/callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('instagram_business_basic,instagram_business_content_publish', $query['scope']);

        $state = json_decode(Crypt::decrypt($query['state']), true);
        $this->assertSame($user->id, $state['user_id']);
        $this->assertNotEmpty($state['nonce']);
    }

    public function test_redirect_requires_authentication(): void
    {
        $this->getJson('/api/auth/instagram/redirect')->assertStatus(401);
    }

    private function fakeOAuthEndpoints(): void
    {
        Http::fake([
            'https://api.instagram.com/oauth/access_token' => function (HttpRequest $request) {
                parse_str($request->body(), $data);

                if (($data['code'] ?? null) === 'auth-code'
                    && ($data['client_secret'] ?? null) === self::TEST_SECRET
                    && ($data['grant_type'] ?? null) === 'authorization_code') {
                    return Http::response([
                        'data' => [[
                            'access_token' => 'short-lived-token',
                            'user_id' => '9001',
                            'permissions' => 'instagram_business_basic,instagram_business_content_publish',
                        ]],
                    ], 200);
                }

                return Http::response(['error_type' => 'OAuthException', 'code' => 400, 'error_message' => 'Invalid code'], 400);
            },
            'https://graph.instagram.com/access_token*' => Http::response([
                'access_token' => 'long-lived-token',
                'token_type' => 'bearer',
                'expires_in' => 5183944,
            ], 200),
            'https://graph.instagram.com/v26.0/me*' => Http::response([
                'id' => '9001',
                'user_id' => '17841400000000001',
                'username' => 'postar_test',
                'name' => 'Postar Test',
                'account_type' => 'BUSINESS',
                'profile_picture_url' => 'https://cdn.example.com/p.jpg',
            ], 200),
        ]);
    }

    public function test_callback_exchanges_code_and_stores_long_lived_token_encrypted(): void
    {
        $user = User::factory()->create();
        $state = $this->startOAuth($user);
        $this->fakeOAuthEndpoints();

        $response = $this->get('/api/auth/instagram/callback?'.http_build_query([
            'code' => 'auth-code#_',
            'state' => $state,
        ]));

        $response->assertRedirect(config('app.frontend_url').'/app/accounts?connected=instagram');

        $account = SocialAccount::where('user_id', $user->id)->where('platform', 'instagram')->firstOrFail();
        $this->assertSame('17841400000000001', $account->platform_user_id);
        $this->assertSame('9001', $account->platform_scoped_user_id);
        $this->assertSame('postar_test', $account->username);
        $this->assertSame('long-lived-token', $account->access_token);
        $this->assertTrue($account->token_expires_at->between(now()->addDays(59), now()->addDays(61)));
        $this->assertTrue($account->is_active);

        // Encrypted at rest and never serialized.
        $raw = DB::table('social_accounts')->where('id', $account->id)->value('access_token');
        $this->assertNotSame('long-lived-token', $raw);
        $this->assertArrayNotHasKey('access_token', $account->toArray());

        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://graph.instagram.com/access_token')
            && str_contains($r->url(), 'grant_type=ig_exchange_token'));
    }

    public function test_callback_state_cannot_be_replayed(): void
    {
        $user = User::factory()->create();
        $state = $this->startOAuth($user);
        $this->fakeOAuthEndpoints();

        $query = http_build_query(['code' => 'auth-code', 'state' => $state]);
        $this->get('/api/auth/instagram/callback?'.$query)->assertRedirectContains('connected=instagram');

        $this->get('/api/auth/instagram/callback?'.$query)
            ->assertRedirect(config('app.frontend_url').'/app/accounts?error=instagram_failed');
    }

    public function test_callback_rejects_tampered_state(): void
    {
        $this->fakeOAuthEndpoints();

        $this->get('/api/auth/instagram/callback?code=auth-code&state=not-a-valid-state')
            ->assertRedirect(config('app.frontend_url').'/app/accounts?error=instagram_failed');

        $this->assertDatabaseMissing('social_accounts', ['platform' => 'instagram']);
        Http::assertNothingSent();
    }

    public function test_callback_handles_user_denial(): void
    {
        $this->get('/api/auth/instagram/callback?error=access_denied&error_reason=user_denied')
            ->assertRedirect(config('app.frontend_url').'/app/accounts?error=instagram_denied');
    }

    // ── Publishing ───────────────────────────────────────────────────────────

    private function makeVideoTarget(array $accountOverrides = []): PostTarget
    {
        // Meta fetches video_url itself, so the public disk must have a public URL.
        Storage::fake('public', ['url' => 'https://api.postar.test/storage']);
        config(['app.url' => 'https://api.postar.test']);
        URL::forceRootUrl('https://api.postar.test');

        $user = User::factory()->create();
        $account = SocialAccount::factory()->create(array_merge([
            'user_id' => $user->id,
            'platform' => 'instagram',
            'platform_user_id' => '17841400000000001',
            'access_token' => 'ig-token',
            'refresh_token' => null,
            'token_expires_at' => now()->addDays(30),
            'is_active' => true,
        ], $accountOverrides));

        $post = Post::factory()->create([
            'user_id' => $user->id,
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
            'caption' => json_encode(['instagram' => 'Hello Reels', 'tiktok' => 'Hello TikTok']),
        ]);

        $path = "media/{$user->id}/clip.mp4";
        Storage::disk('public')->put($path, 'fake-video-bytes');
        $post->media()->create(['type' => 'video', 'path' => $path, 'position' => 0]);

        return $post->targets()->create([
            'social_account_id' => $account->id,
            'status' => 'pending',
            'platform_options' => ['share_to_feed' => true],
        ]);
    }

    private function graph(string $path): string
    {
        return 'https://graph.instagram.com/v26.0/'.$path;
    }

    public function test_reel_is_created_polled_and_published(): void
    {
        $target = $this->makeVideoTarget();

        Http::fake([
            $this->graph('17841400000000001/content_publishing_limit*') => Http::response([
                'data' => [['quota_usage' => 3, 'config' => ['quota_total' => 100, 'quota_duration' => 86400]]],
            ]),
            $this->graph('17841400000000001/media_publish') => Http::response(['id' => 'ig-media-555']),
            $this->graph('17841400000000001/media') => Http::response(['id' => 'container-123']),
            $this->graph('container-123*') => Http::response(['status_code' => 'FINISHED', 'id' => 'container-123']),
        ]);

        SchedulePostJob::dispatchSync($target->id);

        $target->refresh();
        $this->assertSame('published', $target->status);
        $this->assertSame('ig-media-555', $target->platform_post_id);
        $this->assertNotNull($target->published_at);
        $this->assertSame('published', $target->post->fresh()->status);

        Http::assertSent(function (HttpRequest $r) {
            if ($r->url() !== $this->graph('17841400000000001/media')) {
                return false;
            }
            $data = $r->data();

            return $data['media_type'] === 'REELS'
                && $data['caption'] === 'Hello Reels'
                && $data['share_to_feed'] === 'true'
                && str_starts_with($data['video_url'], 'https://api.postar.test/storage/')
                && $r->hasHeader('Authorization', 'Bearer ig-token');
        });

        Http::assertSent(fn (HttpRequest $r) => $r->url() === $this->graph('17841400000000001/media_publish')
            && $r->data()['creation_id'] === 'container-123');
    }

    public function test_container_error_marks_target_failed(): void
    {
        $target = $this->makeVideoTarget();

        Http::fake([
            $this->graph('17841400000000001/content_publishing_limit*') => Http::response(['data' => []]),
            $this->graph('17841400000000001/media') => Http::response(['id' => 'container-err']),
            $this->graph('container-err*') => Http::response(['status_code' => 'ERROR', 'status' => 'Error: unsupported codec']),
        ]);

        SchedulePostJob::dispatchSync($target->id);

        $target->refresh();
        $this->assertSame('failed', $target->status);
        $this->assertStringContainsString('ERROR', $target->error_message);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'media_publish'));
    }

    public function test_container_still_processing_after_max_polls_times_out(): void
    {
        config(['services.instagram.poll_max_attempts' => 1]);
        $target = $this->makeVideoTarget();

        Http::fake([
            $this->graph('17841400000000001/content_publishing_limit*') => Http::response(['data' => []]),
            $this->graph('17841400000000001/media') => Http::response(['id' => 'container-slow']),
            $this->graph('container-slow*') => Http::response(['status_code' => 'IN_PROGRESS']),
        ]);

        SchedulePostJob::dispatchSync($target->id);

        $target->refresh();
        $this->assertSame('failed', $target->status);
        $this->assertStringContainsString('timed out', $target->error_message);
    }

    public function test_expired_token_is_a_permanent_failure(): void
    {
        $target = $this->makeVideoTarget();

        Http::fake([
            $this->graph('17841400000000001/content_publishing_limit*') => Http::response(['data' => []]),
            $this->graph('17841400000000001/media') => Http::response([
                'error' => ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190],
            ], 400),
        ]);

        SchedulePostJob::dispatchSync($target->id);

        $target->refresh();
        $this->assertSame('failed', $target->status);
        $this->assertStringContainsString('access_token_invalid', $target->error_message);
    }

    public function test_instagram_capabilities_reject_images_and_carousels(): void
    {
        $this->assertNotEmpty(PlatformCapabilities::validate('instagram', collect([(object) ['type' => 'image']])));
        $this->assertNotEmpty(PlatformCapabilities::validate('instagram', collect([
            (object) ['type' => 'video'], (object) ['type' => 'video'],
        ])));
        $this->assertSame([], PlatformCapabilities::validate('instagram', collect([(object) ['type' => 'video']])));
    }

    // ── Token refresh ────────────────────────────────────────────────────────

    public function test_refresh_command_extends_expiring_tokens(): void
    {
        $expiring = SocialAccount::factory()->create([
            'platform' => 'instagram',
            'access_token' => 'old-token',
            'refresh_token' => null,
            'token_expires_at' => now()->addDays(5),
        ]);
        $fresh = SocialAccount::factory()->create([
            'platform' => 'instagram',
            'access_token' => 'fresh-token',
            'refresh_token' => null,
            'token_expires_at' => now()->addDays(50),
        ]);

        Http::fake([
            'https://graph.instagram.com/refresh_access_token*' => Http::response([
                'access_token' => 'new-token',
                'token_type' => 'bearer',
                'expires_in' => 5184000,
            ]),
        ]);

        $this->artisan('instagram:refresh-tokens')->assertSuccessful();

        $this->assertSame('new-token', $expiring->fresh()->access_token);
        $this->assertTrue($expiring->fresh()->token_expires_at->greaterThan(now()->addDays(59)));
        $this->assertSame('fresh-token', $fresh->fresh()->access_token);
        Http::assertSentCount(1);
    }

    // ── Meta callbacks ───────────────────────────────────────────────────────

    private function signedRequest(array $payload, string $secret = self::TEST_SECRET): string
    {
        $encodedPayload = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $encodedPayload, $secret, true)), '+/', '-_'), '=');

        return $sig.'.'.$encodedPayload;
    }

    private function instagramAccount(): SocialAccount
    {
        return SocialAccount::factory()->create([
            'platform' => 'instagram',
            'platform_user_id' => '17841400000000001',
            'platform_scoped_user_id' => '9001',
            'access_token' => 'ig-token',
        ]);
    }

    public function test_deauthorize_disables_connection_with_valid_signature(): void
    {
        $account = $this->instagramAccount();

        $this->postJson('/api/instagram/deauthorize', [
            'signed_request' => $this->signedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '9001', 'issued_at' => time()]),
        ])->assertOk();

        $account = SocialAccount::withTrashed()->find($account->id);
        $this->assertTrue($account->trashed());
        $this->assertFalse($account->is_active);
        $this->assertSame('', $account->access_token);
    }

    public function test_deauthorize_rejects_invalid_signature(): void
    {
        $account = $this->instagramAccount();

        $this->postJson('/api/instagram/deauthorize', [
            'signed_request' => $this->signedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '9001'], 'wrong-secret'),
        ])->assertStatus(400);

        $this->assertFalse($account->fresh()->trashed());
    }

    public function test_data_deletion_deletes_data_and_returns_confirmation(): void
    {
        $account = $this->instagramAccount();

        $response = $this->postJson('/api/instagram/data-deletion', [
            'signed_request' => $this->signedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '17841400000000001']),
        ]);

        $response->assertOk()->assertJsonStructure(['url', 'confirmation_code']);
        $this->assertNull(SocialAccount::withTrashed()->find($account->id));

        $code = $response->json('confirmation_code');
        $this->assertStringContainsString('/api/instagram/data-deletion?code='.$code, $response->json('url'));

        $this->getJson('/api/instagram/data-deletion?code='.$code)
            ->assertOk()
            ->assertJson(['status' => 'completed', 'confirmation_code' => $code]);

        $this->getJson('/api/instagram/data-deletion?code=forged0000000000.0000000000000000')->assertNotFound();
    }

    public function test_data_deletion_rejects_missing_signed_request(): void
    {
        $this->postJson('/api/instagram/data-deletion', [])->assertStatus(400);
    }
}
