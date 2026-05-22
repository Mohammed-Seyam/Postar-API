<?php

namespace App\Services;

use App\Models\PostTarget;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class YouTubeService
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $scopes = 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly';

    public function __construct()
    {
        $this->clientId     = config('services.youtube.client_id', '');
        $this->clientSecret = config('services.youtube.client_secret', '');
        $this->redirectUri  = config('services.youtube.redirect_uri', '');
    }

    /**
     * Builds YouTube OAuth URL with encrypted state containing user_id
     */
    public function getAuthUrl(\App\Models\User $user): string
    {
        $stateData = [
            'user_id'   => $user->id,
            'timestamp' => now()->timestamp,
            'nonce'     => Str::random(16),
        ];

        $state = Crypt::encrypt(json_encode($stateData));

        $params = http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => $this->scopes,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    }

    /**
     * Handles the OAuth callback, validates state, checks plan limits, exchanges code, and saves the account
     */
    public function handleCallback(\Illuminate\Http\Request $request): SocialAccount
    {
        $stateStr = $request->input('state');
        if (!$stateStr) {
            throw new \Exception('Missing state parameter.');
        }

        $stateData = json_decode(Crypt::decrypt($stateStr), true);

        if (!isset($stateData['timestamp']) || !isset($stateData['nonce']) || !isset($stateData['user_id'])) {
            throw new \Exception('Invalid state parameter.');
        }

        if (now()->timestamp - $stateData['timestamp'] > 600) {
            throw new \Exception('Auth state expired.');
        }

        $user = \App\Models\User::findOrFail($stateData['user_id']);

        $planLimit = $user->plan->max_social_accounts ?? 0;
        $currentCount = $user->socialAccounts()->count();

        if ($currentCount >= $planLimit && $planLimit !== -1) {
            throw new \Exception('plan_limit_reached');
        }

        $code = $request->input('code');

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $this->redirectUri,
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to exchange code for token: ' . $response->body());
        }

        $tokenData = $response->json();

        $grantedScopesStr = $tokenData['scope'] ?? '';
        $grantedScopes = explode(' ', $grantedScopesStr);
        $requiredScopesList = explode(' ', $this->scopes);

        foreach ($requiredScopesList as $scope) {
            if (!in_array($scope, $grantedScopes) && strpos($grantedScopesStr, $scope) === false) {
                throw new \Exception('youtube_missing_scopes');
            }
        }

        $accessToken = $tokenData['access_token'];

        $profileResponse = Http::withToken($accessToken)
            ->get('https://youtube.googleapis.com/youtube/v3/channels', [
                'part' => 'snippet',
                'mine' => 'true',
            ]);

        if ($profileResponse->failed()) {
            throw new \Exception('Failed to fetch channel profile: ' . $profileResponse->body());
        }

        $profileData = $profileResponse->json('items.0');
        if (!$profileData) {
            throw new \Exception('No YouTube channel found for this account.');
        }

        $channelId = $profileData['id'];
        $snippet = $profileData['snippet'];
        $channelName = $snippet['title'] ?? 'YouTube Channel';
        $avatarUrl = $snippet['thumbnails']['default']['url'] ?? null;

        $existingAccount = SocialAccount::where('platform', 'youtube')
            ->where('platform_user_id', $channelId)
            ->where('user_id', '!=', $user->id)
            ->first();

        if ($existingAccount) {
            throw new \Exception('youtube_already_linked');
        }

        // Handle possible missing refresh token if user already authorized before.
        $refreshToken = isset($tokenData['refresh_token']) 
            ? Crypt::encrypt($tokenData['refresh_token']) 
            : null;

        $updateData = [
            'username'             => $snippet['customUrl'] ?? $channelName,
            'account_name'         => $channelName,
            'avatar_url'           => $avatarUrl,
            'access_token'         => Crypt::encrypt($accessToken),
            'token_expires_at'     => now()->addSeconds($tokenData['expires_in']),
            'scopes'               => json_encode($grantedScopes),
            'is_active'            => true,
        ];

        if ($refreshToken) {
            $updateData['refresh_token'] = $refreshToken;
        }

        return SocialAccount::updateOrCreate(
            [
                'user_id'          => $user->id,
                'platform'         => 'youtube',
                'platform_user_id' => $channelId,
            ],
            $updateData
        );
    }

    /**
     * Checks token expiry (with 5 min buffer) and returns a valid access token
     */
    public function getValidToken(SocialAccount $account): string
    {
        if (now()->addMinutes(5)->gte($account->token_expires_at)) {
            $this->refreshToken($account);
            $account->refresh();
        }

        return Crypt::decrypt($account->access_token);
    }

    /**
     * Refreshes the YouTube access token using the stored refresh token
     */
    public function refreshToken(SocialAccount $account): void
    {
        if (!$account->refresh_token) {
            throw new \Exception('No refresh token available for YouTube account.');
        }

        $refreshToken = Crypt::decrypt($account->refresh_token);

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);

        if ($response->failed()) {
            $account->update(['is_active' => false]);
            throw new \Exception('Failed to refresh YouTube token: ' . $response->body());
        }

        $tokenData = $response->json();

        $account->update([
            'access_token'     => Crypt::encrypt($tokenData['access_token']),
            'token_expires_at' => now()->addSeconds($tokenData['expires_in']),
            'is_active'        => true,
        ]);
    }
}
