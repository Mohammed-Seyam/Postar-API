<?php

namespace App\Services\Platforms;

use App\Interfaces\PlatformInterface;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TikTokService implements PlatformInterface
{
    private string $clientKey;
    private string $clientSecret;
    private string $redirectUri;
    private string $scopes = 'user.info.basic,user.info.profile,video.publish';

    public function __construct()
    {
        $this->clientKey    = config('services.tiktok.client_key') ?? 'sbaw503numdl097thr';
        $this->clientSecret = config('services.tiktok.client_secret') ?? 'hncCRpqNQqamlc0RXusDSJQLOJRVltls';
        $this->redirectUri  = config('services.tiktok.redirect_uri') ?? 'https://postar.blog';
    }
    public function publish(ScheduledPost $post, string $accessToken): string
    {
        try {
            // Mock TikTok API implementation
            // In reality, this would exchange tokens, upload video, and create post
            
            Log::info("Publishing to TikTok: {$post->id}");
            
            // Simulate API call
            // $response = Http::withToken($token)->post('https://open.tiktokapis.com/v2/post/publish/video/init/', ...);
            
            // Return a mock external ID
            return 'tiktok_' . uniqid();
        } catch (\Exception $e) {
            Log::error("TikTok publish failed: " . $e->getMessage());
            throw $e;
        }
    }

    public function getAuthorizationUrl(?string $state = null): array
    {
        $csrfState = $state ?? Str::random(40);

        $params = http_build_query([
            'client_key'   => $this->clientKey,
            'scope'        => $this->scopes,
            'response_type'=> 'code',
            'redirect_uri' => $this->redirectUri,
            'state'        => $csrfState,
        ]);

        $url = 'https://www.tiktok.com/v2/auth/authorize/?' . $params;

        return [
            'url'        => $url,
            'csrf_state' => $csrfState,
        ];
    }

    public function exchangeCodeForToken(string $code): array
    {
        $response = Http::asForm()->post('https://open.tiktokapis.com/v2/oauth/token/', [
            'client_key'    => $this->clientKey,
            'client_secret' => $this->clientSecret,
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $this->redirectUri,
        ]);

        $this->throwIfError($response, 'token exchange');

        return $response->json();
    }

    public function getUserInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get('https://open.tiktokapis.com/v2/user/info/', [
                'fields' => 'open_id,union_id,avatar_url,display_name,username',
            ]);

        $this->throwIfError($response, 'user info');

        return $response->json('data.user', []);
    }

    public function saveAccount(User $user, array $tokenData, array $userInfo): SocialAccount
    {
        return SocialAccount::updateOrCreate(
            [
                'user_id'          => $user->id,
                'platform'         => 'tiktok',
                'platform_user_id' => $tokenData['open_id'],
            ],
            [
                'username'             => $userInfo['username'] ?? null,
                'account_name'         => $userInfo['display_name'] ?? null,
                'avatar_url'           => $userInfo['avatar_url'] ?? null,
                'access_token'         => $tokenData['access_token'],
                'refresh_token'        => $tokenData['refresh_token'],
                'token_expires_at'     => now()->addSeconds($tokenData['expires_in']),
                'refresh_expires_at'   => now()->addSeconds($tokenData['refresh_expires_in']),
                'scopes'               => $tokenData['scope'] ?? null,
                'is_active'            => true,
            ]
        );
    }

    public function refreshToken(SocialAccount $account): SocialAccount
    {
        $response = Http::asForm()->post('https://open.tiktokapis.com/v2/oauth/token/', [
            'client_key'    => $this->clientKey,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);

        $this->throwIfError($response, 'token refresh');

        $data = $response->json();

        $account->update([
            'access_token'       => $data['access_token'],
            'refresh_token'      => $data['refresh_token'],
            'token_expires_at'   => now()->addSeconds($data['expires_in']),
            'refresh_expires_at' => now()->addSeconds($data['refresh_expires_in']),
            'scopes'             => $data['scope'] ?? $account->scopes,
            'is_active'          => true,
        ]);

        return $account->fresh();
    }

    public function revokeToken(SocialAccount $account): void
    {
        Http::asForm()->post('https://open.tiktokapis.com/v2/oauth/revoke/', [
            'client_key'    => $this->clientKey,
            'client_secret' => $this->clientSecret,
            'token'         => $account->access_token,
        ]);

        $account->delete();
    }

    public function getValidToken(SocialAccount $account): string
    {
        if ($account->token_expires_at?->subMinutes(5)->isPast() ?? true) {
            $account = $this->refreshToken($account);
        }

        return $account->access_token;
    }

    private function throwIfError(Response $response, string $context): void
    {
        $body      = $response->json();
        $errorCode = $body['error']['code'] ?? null;

        // TikTok returns {"error": {"code": "ok"}} on success — that is NOT an error
        if ($response->failed() || ($errorCode !== null && $errorCode !== 'ok')) {
            $errorMessage = $body['error']['message'] ?? $body['error_description'] ?? $response->body();
            Log::error("TikTok {$context} error", [
                'context' => $context,
                'code'    => $errorCode,
                'message' => $errorMessage,
                'status'  => $response->status(),
            ]);
            throw new \RuntimeException("TikTok {$context} failed: {$errorMessage}");
        }
    }
}
