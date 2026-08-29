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

    private string $scopes;

    public function __construct()
    {
        $this->clientKey = config('services.tiktok.client_key') ?? '';
        $this->clientSecret = config('services.tiktok.client_secret') ?? '';
        $this->redirectUri = config('services.tiktok.redirect_uri') ?? 'https://postar.blog';
        $this->scopes = config('services.tiktok.scopes') ?? 'user.info.basic,user.info.profile,video.publish,video.list';
    }

    public function getScopes(): string
    {
        return $this->scopes;
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
            return 'tiktok_'.uniqid();
        } catch (\Exception $e) {
            Log::error('TikTok publish failed: '.$e->getMessage());
            throw $e;
        }
    }

    public function getAuthorizationUrl(?string $state = null, ?string $codeVerifier = null): array
    {
        $csrfState = $state ?? Str::random(40);

        $params = [
            'client_key' => $this->clientKey,
            'scope' => $this->scopes,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'state' => $csrfState,
            'disable_auto_auth' => 1,
        ];

        if ($codeVerifier) {
            $rawHash = hash('sha256', $codeVerifier, true);
            $codeChallenge = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($rawHash));
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = 'S256';
        }

        $url = 'https://www.tiktok.com/v2/auth/authorize/?'.http_build_query($params);

        return [
            'url' => $url,
            'csrf_state' => $csrfState,
        ];
    }

    public function exchangeCodeForToken(string $code, ?string $codeVerifier = null): array
    {
        $params = [
            'client_key' => $this->clientKey,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
        ];

        if ($codeVerifier) {
            $params['code_verifier'] = $codeVerifier;
        }

        $response = Http::asForm()->post('https://open.tiktokapis.com/v2/oauth/token/', $params);

        $this->throwIfError($response, 'token exchange');

        return $response->json();
    }

    public function getUserInfo(string $accessToken): array
    {
        $scopeArray = explode(',', $this->scopes);

        $fields = ['open_id', 'union_id', 'avatar_url', 'display_name'];
        if (in_array('user.info.profile', $scopeArray)) {
            $fields[] = 'username';
        }

        $response = Http::withToken($accessToken)
            ->get('https://open.tiktokapis.com/v2/user/info/', [
                'fields' => implode(',', $fields),
            ]);

        $this->throwIfError($response, 'user info');

        return $response->json('data.user', []);
    }

    public function getCreatorInfo(SocialAccount $account): array
    {
        $accessToken = $this->getValidToken($account);

        $response = Http::withToken($accessToken)
            ->withHeaders([
                'Content-Type' => 'application/json; charset=UTF-8',
            ])
            ->post('https://open.tiktokapis.com/v2/post/publish/creator_info/query/', (object) []);

        $this->throwIfError($response, 'creator info');

        return $response->json('data', []);
    }

    public function saveAccount(User $user, array $tokenData, array $userInfo): SocialAccount
    {
        $account = SocialAccount::withTrashed()->where([
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'platform_user_id' => $tokenData['open_id'],
        ])->first();

        $updateData = [
            'username' => $userInfo['username'] ?? null,
            'account_name' => $userInfo['display_name'] ?? null,
            'avatar_url' => $userInfo['avatar_url'] ?? null,
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'],
            'token_expires_at' => now()->addSeconds($tokenData['expires_in']),
            'refresh_expires_at' => now()->addSeconds($tokenData['refresh_expires_in']),
            'scopes' => $tokenData['scope'] ?? null,
            'is_active' => true,
        ];

        if ($account) {
            $account->restore();
            $account->update($updateData);
        } else {
            $account = SocialAccount::create(array_merge([
                'user_id' => $user->id,
                'platform' => 'tiktok',
                'platform_user_id' => $tokenData['open_id'],
            ], $updateData));
        }

        return $account;
    }

    public function refreshToken(SocialAccount $account): SocialAccount
    {
        $response = Http::asForm()->post('https://open.tiktokapis.com/v2/oauth/token/', [
            'client_key' => $this->clientKey,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);

        $this->throwIfError($response, 'token refresh');

        $data = $response->json();

        $account->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'token_expires_at' => now()->addSeconds($data['expires_in']),
            'refresh_expires_at' => now()->addSeconds($data['refresh_expires_in']),
            'scopes' => $data['scope'] ?? $account->scopes,
            'is_active' => true,
        ]);

        return $account->fresh();
    }

    public function revokeToken(SocialAccount $account): void
    {
        Http::asForm()->post('https://open.tiktokapis.com/v2/oauth/revoke/', [
            'client_key' => $this->clientKey,
            'client_secret' => $this->clientSecret,
            'token' => $account->access_token,
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
        $body = $response->json();
        $hasError = false;
        $errorCode = null;
        $errorMessage = null;

        if ($response->failed()) {
            $hasError = true;
        }

        if (is_array($body) && isset($body['error'])) {
            $error = $body['error'];
            if (is_array($error)) {
                $errorCode = $error['code'] ?? null;
                $errorMessage = $error['message'] ?? null;
                if ($errorCode !== null && $errorCode !== 'ok') {
                    $hasError = true;
                }
            } elseif (is_string($error)) {
                $errorCode = $error;
                $errorMessage = $body['error_description'] ?? null;
                if ($error !== 'ok') {
                    $hasError = true;
                }
            }
        }

        if ($hasError) {
            $errorMessage = $errorMessage ?? $body['error_description'] ?? $response->body();
            Log::error("TikTok {$context} error", [
                'context' => $context,
                'code' => $errorCode,
                'message' => $errorMessage,
                'status' => $response->status(),
            ]);
            throw new \RuntimeException("TikTok {$context} failed: {$errorMessage}");
        }
    }
}
