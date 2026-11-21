<?php

namespace App\Services\Platforms;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Instagram API with Instagram Login (graph.instagram.com).
 *
 * Owns the OAuth flow (authorize URL, code → short-lived → long-lived token),
 * token refresh, Graph HTTP calls, and Meta's signed_request verification for
 * the deauthorize / data-deletion callbacks. Mirrors YouTubeService: the
 * controller only maps exception messages to frontend error codes.
 *
 * Tokens are stored through the SocialAccount `encrypted` casts (single
 * encryption, like TikTok) and are never logged.
 */
class InstagramService
{
    private const STATE_TTL_SECONDS = 600;

    private const AUTHORIZE_URL = 'https://www.instagram.com/oauth/authorize';

    private const TOKEN_URL = 'https://api.instagram.com/oauth/access_token';

    private string $appId;

    private string $appSecret;

    private string $redirectUri;

    private string $apiVersion;

    private string $scopes;

    private string $graphUrl;

    public function __construct()
    {
        $this->appId = (string) config('services.instagram.app_id', '');
        $this->appSecret = (string) config('services.instagram.app_secret', '');
        $this->redirectUri = (string) config('services.instagram.redirect_uri', '');
        $this->apiVersion = (string) config('services.instagram.api_version', 'v26.0');
        $this->scopes = (string) config('services.instagram.scopes', 'instagram_business_basic,instagram_business_content_publish');
        $this->graphUrl = rtrim((string) config('services.instagram.graph_url', 'https://graph.instagram.com'), '/');
    }

    public function getScopes(): string
    {
        return $this->scopes;
    }

    // -------------------------------------------------------------------------
    // OAuth
    // -------------------------------------------------------------------------

    /**
     * Build the Instagram authorize URL. The state is encrypted + MAC'd with
     * APP_KEY (tamper-proof), bound to the authenticated Postar user, expires
     * after 10 minutes, and carries a one-time nonce cached server-side.
     */
    public function getAuthUrl(User $user): string
    {
        $nonce = Str::random(32);

        Cache::put($this->nonceCacheKey($nonce), (string) $user->id, self::STATE_TTL_SECONDS);

        $state = Crypt::encrypt(json_encode([
            'user_id' => $user->id,
            'timestamp' => now()->timestamp,
            'nonce' => $nonce,
        ]));

        $params = http_build_query([
            'client_id' => $this->appId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => $this->scopes,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return self::AUTHORIZE_URL.'?'.$params;
    }

    /**
     * Validate state, check plan limits, exchange the code for a long-lived
     * token, fetch the profile and save (or restore) the social account.
     *
     * Exception messages that the controller maps to frontend error codes:
     * plan_limit_reached, instagram_missing_scopes, instagram_already_linked,
     * instagram_not_professional. Anything else → instagram_failed.
     */
    public function handleCallback(Request $request): SocialAccount
    {
        $user = $this->validateState($request->input('state'));

        $planLimit = (int) $user->plan->max_social_accounts;
        $currentCount = $user->socialAccounts()->count();

        if ($currentCount >= $planLimit) {
            throw new \Exception('plan_limit_reached');
        }

        $code = (string) $request->input('code', '');
        if ($code === '') {
            throw new \Exception('Missing authorization code.');
        }

        $shortLived = $this->exchangeCodeForToken($code);

        $granted = $shortLived['permissions'];
        foreach (array_filter(array_map('trim', explode(',', $this->scopes))) as $scope) {
            if (! in_array($scope, $granted, true)) {
                Log::warning('Instagram missing scope', ['user_id' => $user->id, 'missing_scope' => $scope]);
                throw new \Exception('instagram_missing_scopes');
            }
        }

        $longLived = $this->exchangeForLongLivedToken($shortLived['access_token']);
        $profile = $this->getProfile($longLived['access_token']);

        $accountType = strtoupper((string) ($profile['account_type'] ?? ''));
        if ($accountType === 'PERSONAL') {
            throw new \Exception('instagram_not_professional');
        }

        // Professional account id: used for /{ig-user-id}/media publishing.
        $igUserId = (string) ($profile['user_id'] ?? $profile['id'] ?? $shortLived['user_id']);
        // App-scoped id returned by the token exchange (may appear in signed_request).
        $scopedId = (string) ($shortLived['user_id'] ?: ($profile['id'] ?? ''));

        $existingAccount = SocialAccount::withTrashed()
            ->where('platform', 'instagram')
            ->where('platform_user_id', $igUserId)
            ->where('user_id', '!=', $user->id)
            ->first();

        if ($existingAccount) {
            throw new \Exception('instagram_already_linked');
        }

        $updateData = [
            'platform_scoped_user_id' => $scopedId ?: null,
            'username' => $profile['username'] ?? null,
            'account_name' => $profile['name'] ?? ($profile['username'] ?? null),
            'avatar_url' => $profile['profile_picture_url'] ?? null,
            'access_token' => $longLived['access_token'],
            'refresh_token' => null,
            'token_expires_at' => now()->addSeconds((int) ($longLived['expires_in'] ?? 5184000)),
            'refresh_expires_at' => null,
            'scopes' => implode(',', $granted),
            'is_active' => true,
        ];

        $account = SocialAccount::withTrashed()->where([
            'user_id' => $user->id,
            'platform' => 'instagram',
            'platform_user_id' => $igUserId,
        ])->first();

        if ($account) {
            $account->restore();
            $account->update($updateData);
        } else {
            $account = SocialAccount::create(array_merge([
                'user_id' => $user->id,
                'platform' => 'instagram',
                'platform_user_id' => $igUserId,
            ], $updateData));
        }

        return $account;
    }

    /**
     * Decrypt and validate the OAuth state; returns the Postar user it is bound to.
     */
    private function validateState(?string $state): User
    {
        if (! $state) {
            throw new \Exception('Missing state parameter.');
        }

        $stateData = json_decode(Crypt::decrypt($state), true);

        if (! is_array($stateData) || ! isset($stateData['user_id'], $stateData['timestamp'], $stateData['nonce'])) {
            throw new \Exception('Invalid state parameter.');
        }

        if (now()->timestamp - (int) $stateData['timestamp'] > self::STATE_TTL_SECONDS) {
            throw new \Exception('Auth state expired.');
        }

        // One-time use: the nonce must still be cached for the same user.
        $cachedUserId = Cache::pull($this->nonceCacheKey((string) $stateData['nonce']));
        if ($cachedUserId === null || ! hash_equals((string) $cachedUserId, (string) $stateData['user_id'])) {
            throw new \Exception('Auth state already used or unknown.');
        }

        return User::findOrFail($stateData['user_id']);
    }

    private function nonceCacheKey(string $nonce): string
    {
        return 'instagram_oauth_nonce:'.hash('sha256', $nonce);
    }

    /**
     * Exchange the authorization code for a short-lived token.
     *
     * @return array{access_token: string, user_id: string, permissions: array<int, string>}
     */
    public function exchangeCodeForToken(string $code): array
    {
        // Instagram appends "#_" to the code in the redirect URL.
        $code = preg_replace('/#_$/', '', $code);

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
            'code' => $code,
        ]);

        $this->throwIfError($response, 'token exchange');

        // Both the flat and the {"data":[{...}]} response shapes exist.
        $data = $response->json('data.0') ?? $response->json();

        if (empty($data['access_token'])) {
            throw new \RuntimeException('Instagram token exchange failed: no access token returned.');
        }

        $permissions = $data['permissions'] ?? [];
        if (is_string($permissions)) {
            $permissions = explode(',', $permissions);
        }

        return [
            'access_token' => (string) $data['access_token'],
            'user_id' => (string) ($data['user_id'] ?? ''),
            'permissions' => array_values(array_filter(array_map('trim', (array) $permissions))),
        ];
    }

    /**
     * Exchange a short-lived token for a long-lived (60 day) token.
     *
     * @return array{access_token: string, expires_in: int}
     */
    public function exchangeForLongLivedToken(string $shortLivedToken): array
    {
        $response = Http::get("{$this->graphUrl}/access_token", [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $this->appSecret,
            'access_token' => $shortLivedToken,
        ]);

        $this->throwIfError($response, 'long-lived token exchange');

        $token = $response->json('access_token');
        if (! $token) {
            throw new \RuntimeException('Instagram long-lived token exchange failed: no access token returned.');
        }

        return [
            'access_token' => (string) $token,
            'expires_in' => (int) ($response->json('expires_in') ?? 5184000),
        ];
    }

    public function getProfile(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get($this->graphEndpoint('me'), [
            'fields' => 'user_id,username,name,profile_picture_url,account_type',
        ]);

        $this->throwIfError($response, 'profile');

        return $response->json() ?? [];
    }

    // -------------------------------------------------------------------------
    // Tokens
    // -------------------------------------------------------------------------

    /**
     * Refresh a long-lived token (must be ≥24h old and not expired). Called by
     * instagram:refresh-tokens and by SchedulePostJob before publishing.
     */
    public function refreshToken(SocialAccount $account): SocialAccount
    {
        $response = Http::get("{$this->graphUrl}/refresh_access_token", [
            'grant_type' => 'ig_refresh_token',
            'access_token' => $account->access_token,
        ]);

        try {
            $this->throwIfError($response, 'token refresh');
        } catch (\RuntimeException $e) {
            if ($this->isOAuthError($response)) {
                $account->update(['is_active' => false]);
            }
            throw $e;
        }

        $account->update([
            'access_token' => (string) $response->json('access_token'),
            'token_expires_at' => now()->addSeconds((int) ($response->json('expires_in') ?? 5184000)),
            'is_active' => true,
        ]);

        return $account->fresh();
    }

    public function getValidToken(SocialAccount $account): string
    {
        if ($account->token_expires_at && $account->token_expires_at->isPast()) {
            $account->update(['is_active' => false]);
            throw new \RuntimeException('Instagram access_token_invalid: the token has expired. Please reconnect the account.');
        }

        return (string) $account->access_token;
    }

    // -------------------------------------------------------------------------
    // Meta signed_request (deauthorize / data deletion)
    // -------------------------------------------------------------------------

    /**
     * Verify and decode a Meta signed_request ("<sig>.<payload>", base64url,
     * HMAC-SHA256 with the app secret). Returns null when invalid.
     */
    public function parseSignedRequest(?string $signedRequest): ?array
    {
        if (! $signedRequest || $this->appSecret === '' || substr_count($signedRequest, '.') !== 1) {
            return null;
        }

        [$encodedSig, $payload] = explode('.', $signedRequest, 2);

        $sig = $this->base64UrlDecode($encodedSig);
        $data = json_decode((string) $this->base64UrlDecode($payload), true);

        if ($sig === null || ! is_array($data)) {
            return null;
        }

        if (strtoupper((string) ($data['algorithm'] ?? '')) !== 'HMAC-SHA256') {
            return null;
        }

        $expected = hash_hmac('sha256', $payload, $this->appSecret, true);

        if (! hash_equals($expected, $sig)) {
            return null;
        }

        return $data;
    }

    /**
     * All Postar connections for an Instagram user id from a signed_request.
     * Meta may send either the professional account id or the app-scoped id.
     */
    public function accountsForInstagramUser(string $instagramUserId, bool $withTrashed = false)
    {
        $query = SocialAccount::query()
            ->where('platform', 'instagram')
            ->where(function ($q) use ($instagramUserId) {
                $q->where('platform_user_id', $instagramUserId)
                    ->orWhere('platform_scoped_user_id', $instagramUserId);
            });

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->get();
    }

    private function base64UrlDecode(string $input): ?string
    {
        $decoded = base64_decode(strtr($input, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    // -------------------------------------------------------------------------
    // Graph helpers
    // -------------------------------------------------------------------------

    public function graphEndpoint(string $path): string
    {
        return "{$this->graphUrl}/{$this->apiVersion}/".ltrim($path, '/');
    }

    /**
     * Throw a RuntimeException for failed Graph responses. Message keywords are
     * matched by SchedulePostJob::isPermanentFailure() so unrecoverable errors
     * (expired token, missing permission) are not retried.
     */
    public function throwIfError(Response $response, string $context): void
    {
        $body = $response->json();
        $error = is_array($body) ? ($body['error'] ?? null) : null;

        if (! $response->failed() && ! $error) {
            return;
        }

        $code = null;
        $message = null;

        if (is_array($error)) {
            $code = $error['code'] ?? null;
            $message = $error['error_user_msg'] ?? $error['message'] ?? null;
        } elseif (is_string($error)) {
            $message = $body['error_description'] ?? $body['error_message'] ?? $error;
        }

        $message = $message ?? ($body['error_message'] ?? 'HTTP '.$response->status());

        $marker = '';
        if ($this->isOAuthError($response)) {
            $marker = ' [access_token_invalid]';
        } elseif (in_array((int) $code, [10, 200], true)) {
            $marker = ' [instagram_permission_denied]';
        }

        Log::error("Instagram {$context} error", [
            'context' => $context,
            'code' => $code,
            'message' => $message,
            'status' => $response->status(),
        ]);

        throw new \RuntimeException("Instagram {$context} failed: {$message}".($code !== null ? " (code {$code})" : '').$marker);
    }

    private function isOAuthError(Response $response): bool
    {
        $error = $response->json('error');

        // Graph error code 190 = invalid / expired / revoked access token.
        return is_array($error) && (int) ($error['code'] ?? 0) === 190;
    }
}
