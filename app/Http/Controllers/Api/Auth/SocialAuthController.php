<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SocialAuthController extends Controller
{
    public function redirect(Request $request, $platform)
    {
        if ($platform === 'tiktok') {
            /** @var \App\Services\Platforms\TikTokService $tiktok */
            $tiktok = app(\App\Services\Platforms\TikTokService::class);

            $codeVerifier = \Illuminate\Support\Str::random(64);

            // Securely pass the user ID and code_verifier in the state parameter
            $stateData = [
                'user_id' => $request->user()->id,
                'ts' => time(),
                'nonce' => \Illuminate\Support\Str::random(16),
                'code_verifier' => $codeVerifier,
            ];
            $signedState = \Illuminate\Support\Facades\Crypt::encrypt($stateData);

            $authData = $tiktok->getAuthorizationUrl($signedState, $codeVerifier);

            return response()->json([
                'url' => $authData['url'],
            ]);
        }

        if ($platform === 'youtube') {
            $youtube = app(\App\Services\YouTubeService::class);
            $loginHint = $request->query('login_hint');

            return response()->json([
                'url' => $youtube->getAuthUrl($request->user(), $loginHint),
            ]);
        }

        if ($platform === 'instagram') {
            /** @var \App\Services\Platforms\InstagramService $instagram */
            $instagram = app(\App\Services\Platforms\InstagramService::class);

            return response()->json([
                'url' => $instagram->getAuthUrl($request->user()),
            ]);
        }

        // Mock authorization URL generation.
        // E.g., for YouTube: Socialite::driver('youtube')->stateless()->redirect()->getTargetUrl();
        $authUrl = url("/oauth/{$platform}/mock-redirect");

        return response()->json([
            'url' => $authUrl,
        ]);
    }

    public function callback(Request $request, $platform)
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:4200');

        if ($platform === 'youtube') {
            try {
                $youtube = app(\App\Services\YouTubeService::class);
                $youtube->handleCallback($request);

                return redirect("{$frontendUrl}/app/accounts?connected=youtube");
            } catch (\Exception $e) {
                $errorType = 'youtube_failed';
                if ($e->getMessage() === 'plan_limit_reached') {
                    $errorType = 'plan_limit_reached';
                } elseif ($e->getMessage() === 'youtube_missing_scopes') {
                    $errorType = 'youtube_missing_scopes';
                } elseif ($e->getMessage() === 'youtube_already_linked') {
                    $errorType = 'youtube_already_linked';
                }
                \Illuminate\Support\Facades\Log::error('YouTube callback exception', [
                    'message' => $e->getMessage(),
                ]);

                return redirect("{$frontendUrl}/app/accounts?error={$errorType}");
            }
        }

        if ($platform === 'instagram') {
            if ($request->filled('error')) {
                \Illuminate\Support\Facades\Log::warning('Instagram auth denied', [
                    'error' => $request->input('error'),
                    'reason' => $request->input('error_reason'),
                ]);

                return redirect("{$frontendUrl}/app/accounts?error=instagram_denied");
            }

            try {
                /** @var \App\Services\Platforms\InstagramService $instagram */
                $instagram = app(\App\Services\Platforms\InstagramService::class);
                $instagram->handleCallback($request);

                return redirect("{$frontendUrl}/app/accounts?connected=instagram");
            } catch (\Throwable $e) {
                $knownErrors = [
                    'plan_limit_reached',
                    'instagram_missing_scopes',
                    'instagram_already_linked',
                    'instagram_not_professional',
                ];
                $errorType = in_array($e->getMessage(), $knownErrors, true) ? $e->getMessage() : 'instagram_failed';

                \Illuminate\Support\Facades\Log::error('Instagram callback exception', [
                    'message' => $e->getMessage(),
                ]);

                return redirect("{$frontendUrl}/app/accounts?error={$errorType}");
            }
        }

        if ($platform === 'tiktok') {
            if ($request->filled('error')) {
                \Illuminate\Support\Facades\Log::warning('TikTok auth denied', [
                    'error' => $request->input('error'),
                    'description' => $request->input('error_description'),
                ]);

                return redirect("{$frontendUrl}/app/accounts?error=tiktok_denied");
            }

            try {
                // Decrypt state to get the user ID
                $stateData = \Illuminate\Support\Facades\Crypt::decrypt($request->input('state'));
                $user = \App\Models\User::findOrFail($stateData['user_id']);

                // Security check: timestamp (optional, e.g. within 1 hour)
                if ($stateData['ts'] < time() - 3600) {
                    throw new \Exception('Auth state expired');
                }

                // We ensure we don't exceed plan limits when creating a new account.
                $planLimit = (int) $user->plan->max_social_accounts;
                $currentCount = $user->socialAccounts()->count();

                if ($currentCount >= $planLimit) {
                    return redirect("{$frontendUrl}/app/accounts?error=plan_limit_reached");
                }

                /** @var \App\Services\Platforms\TikTokService $tiktok */
                $tiktok = app(\App\Services\Platforms\TikTokService::class);

                $code = $request->input('code');
                $codeVerifier = $stateData['code_verifier'] ?? null;
                $tokenData = $tiktok->exchangeCodeForToken($code, $codeVerifier);

                // Task 3: Validate that required scopes were actually granted by the user
                $grantedScopes = explode(',', $tokenData['scope'] ?? '');
                $requiredScopes = explode(',', $tiktok->getScopes());

                foreach ($requiredScopes as $scope) {
                    $scope = trim($scope);
                    if (! empty($scope) && ! in_array($scope, $grantedScopes) && strpos($tokenData['scope'] ?? '', $scope) === false) {
                        \Illuminate\Support\Facades\Log::warning('TikTok missing scope', [
                            'user_id' => $user->id,
                            'missing_scope' => $scope,
                            'granted_scopes' => $grantedScopes,
                        ]);
                        // Do not fail the entire connection, TikTok V2 doesn't always echo back scopes perfectly.
                        // return redirect("{$frontendUrl}/app/accounts?error=tiktok_missing_scopes");
                    }
                }

                $userInfo = $tiktok->getUserInfo($tokenData['access_token']);

                // Task 2: Check if this TikTok account is already linked to a different Postar user
                $existingAccount = \App\Models\SocialAccount::where('platform', 'tiktok')
                    ->where('platform_user_id', $userInfo['open_id'])
                    ->where('user_id', '!=', $user->id)
                    ->first();

                if ($existingAccount) {
                    return redirect("{$frontendUrl}/app/accounts?error=tiktok_already_linked");
                }

                $tiktok->saveAccount($user, $tokenData, $userInfo);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('TikTok callback exception', [
                    'message' => $e->getMessage(),
                ]);

                return redirect("{$frontendUrl}/app/accounts?error=tiktok_failed");
            }

            return redirect("{$frontendUrl}/app/accounts?connected=tiktok");
        }

        $user = $request->user();
        if (! $user) {
            return redirect("{$frontendUrl}/dashboard?error=unauthenticated");
        }

        $planLimit = (int) $user->plan->max_social_accounts;
        $currentCount = $user->socialAccounts()->count();

        if ($currentCount >= $planLimit) {
            return redirect("{$frontendUrl}/settings/accounts?error=plan_limit_reached");
        }

        // Mock saving the account securely.
        $user->socialAccounts()->updateOrCreate(
            ['platform' => $platform, 'platform_user_id' => 'mock_id_'.time()],
            [
                'username' => 'Mock '.ucfirst($platform).' User',
                'access_token' => 'mock_access_token_encrypted',
                'refresh_token' => 'mock_refresh_token_encrypted',
                'token_expires_at' => now()->addHours(2),
                'refresh_expires_at' => now()->addDays(30),
                'scopes' => 'read,write',
                'is_active' => true,
            ]
        );

        return redirect("{$frontendUrl}/app/accounts?connected={$platform}");
    }
}
