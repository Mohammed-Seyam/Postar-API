<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetConfirmationMail;
use App\Mail\PasswordResetMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PasswordResetController extends Controller
{
    /**
     * Step 1 - Send email for password reset with timing padding and rate limiting.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $startTime = microtime(true);

        // Input Validation (Reject > 254 chars, validate email format)
        $request->validate([
            'email' => 'required|string|email|max:254',
        ]);

        // Input Normalization (lowercase and trim)
        $email = strtolower(trim($request->input('email')));

        // Dual rate limiting check
        $ip = $request->ip();
        $ipBlockSec = $this->checkRateLimit('ip', $ip, 5);
        $emailBlockSec = $this->checkRateLimit('email', $email, 3);

        if ($ipBlockSec !== null || $emailBlockSec !== null) {
            $maxBlock = max($ipBlockSec ?? 0, $emailBlockSec ?? 0);

            // Artificial padding even on rate limit triggers to prevent timing differences
            $this->padResponse($startTime, 2000);

            return response()->json([
                'error' => 'Too many requests.',
                'message' => "Please try again after {$maxBlock} seconds.",
                'retry_after' => $maxBlock,
            ], 429)->header('Retry-After', $maxBlock);
        }

        // Database lookup
        $user = \App\Models\User::where('email', $email)->first();

        if ($user) {
            // Invalidate old tokens for this user first
            DB::table('password_reset_tokens')->where('user_id', $user->id)->delete();

            // Cryptographically secure token (64 bytes of entropy)
            $rawToken = bin2hex(random_bytes(64));
            $tokenHash = hash('sha256', $rawToken);

            // Store hashed token tied to user ID
            DB::table('password_reset_tokens')->insert([
                'user_id' => $user->id,
                'token' => $tokenHash,
                'created_at' => now(),
            ]);

            // Log internally (timestamp, hashed email, IP)
            Log::info('Password reset token generated.', [
                'ip' => $ip,
                'email_hash' => hash('sha256', $email),
            ]);

            // Send Reset Email
            try {
                $frontendUrl = config('app.frontend_url') ?? 'http://localhost:4200';
                $resetUrl = rtrim($frontendUrl, '/').'/reset-password/'.$rawToken;
                Mail::to($user->email)->send(new PasswordResetMail($resetUrl));
            } catch (\Exception $e) {
                Log::error('Failed to send password reset email: '.$e->getMessage());
            }
        }

        // Timing side-channel padding (pad to exactly 2000ms)
        $this->padResponse($startTime, 2000);

        // Anti-enumeration: identical response
        return response()->json([
            'message' => 'If this email exists, you will receive a reset link shortly',
        ], 200);
    }

    /**
     * Step 5 - Validate the token when landing page loads.
     */
    public function validateResetToken(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $rawToken = $request->input('token');
        $tokenHash = hash('sha256', $rawToken);

        // Check if token exists in DB
        $tokenRow = DB::table('password_reset_tokens')->where('token', $tokenHash)->first();

        if (! $tokenRow) {
            return response()->json([
                'error' => 'This link is invalid or has expired.',
            ], 400);
        }

        // Check hard expiry (60 minutes)
        $createdAt = \Illuminate\Support\Carbon::parse($tokenRow->created_at);
        if ($createdAt->addMinutes(60)->isPast()) {
            // Delete expired token immediately
            DB::table('password_reset_tokens')->where('user_id', $tokenRow->user_id)->delete();

            return response()->json([
                'error' => 'This link is invalid or has expired.',
            ], 400);
        }

        // Check correct user
        $user = \App\Models\User::find($tokenRow->user_id);
        if (! $user) {
            DB::table('password_reset_tokens')->where('user_id', $tokenRow->user_id)->delete();

            return response()->json([
                'error' => 'This link is invalid or has expired.',
            ], 400);
        }

        // Single-use: Delete the token from DB immediately after validating it
        DB::table('password_reset_tokens')->where('user_id', $user->id)->delete();

        // Create a short-lived cache session key (valid for 5 minutes) to authorize password reset
        $sessionKey = bin2hex(random_bytes(32));
        Cache::put("reset_session_{$sessionKey}", [
            'user_id' => $user->id,
            'ip' => $request->ip(),
        ], now()->addMinutes(5));

        return response()->json([
            'success' => true,
            'session_key' => $sessionKey,
        ], 200);
    }

    /**
     * Step 6 - Save new password and trigger post-reset security invalidations.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'session_key' => 'required|string',
            'password' => [
                'required',
                'string',
                \Illuminate\Validation\Rules\Password::min(8)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
                'confirmed',
            ],
        ], [
            'password.min' => 'يجب أن تتكون كلمة المرور من 8 أحرف على الأقل.',
            'password.mixed' => 'يجب أن تحتوي كلمة المرور على حرف كبير وحرف صغير واحد على الأقل.',
            'password.numbers' => 'يجب أن تحتوي كلمة المرور على رقم واحد على الأقل.',
            'password.symbols' => 'يجب أن تحتوي كلمة المرور على رمز خاص واحد على الأقل.',
            'password.confirmed' => 'تأكيد كلمة المرور غير متطابق.',
        ]);

        $sessionKey = $request->input('session_key');
        $sessionData = Cache::get("reset_session_{$sessionKey}");

        if (! $sessionData) {
            return response()->json([
                'error' => 'The reset session has expired. Please request a new link.',
            ], 400);
        }

        $password = $request->input('password');

        // HaveIBeenPwned k-anonymity validation
        if ($this->isPasswordPwned($password)) {
            return response()->json([
                'errors' => [
                    'password' => ['This password has been leaked in a data breach. Please choose a different one.'],
                ],
            ], 422);
        }

        $user = \App\Models\User::find($sessionData['user_id']);
        if (! $user) {
            return response()->json([
                'error' => 'User not found.',
            ], 400);
        }

        // Update password
        $user->password = Hash::make($password);
        $user->save();

        // Invalidate the session key
        Cache::forget("reset_session_{$sessionKey}");

        // Invalidate all active Sanctum tokens
        $user->tokens()->delete();

        // Invalidate all active database sessions
        DB::table('sessions')->where('user_id', $user->id)->delete();

        // Delete all other password reset tokens for this user
        DB::table('password_reset_tokens')->where('user_id', $user->id)->delete();

        // Send confirmation email
        try {
            Mail::to($user->email)->send(new PasswordResetConfirmationMail);
        } catch (\Exception $e) {
            Log::error('Failed to send password reset confirmation email: '.$e->getMessage());
        }

        // Log event internally
        Log::info('Password reset successful.', [
            'user_id' => $user->id,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Password reset successfully',
        ], 200);
    }

    /**
     * Check HaveIBeenPwned API using k-anonymity.
     */
    private function isPasswordPwned(string $password): bool
    {
        $sha1 = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        try {
            $response = Http::timeout(5)->get("https://api.pwnedpasswords.com/range/{$prefix}");
            if ($response->successful()) {
                $body = $response->body();

                return str_contains($body, $suffix);
            }
        } catch (\Exception $e) {
            Log::warning('HaveIBeenPwned check failed: '.$e->getMessage());
        }

        return false;
    }

    /**
     * Multi-stage rate limiting with escalating blocks.
     */
    private function checkRateLimit(string $type, string $value, int $maxRequests): ?int
    {
        $blockedUntilKey = "fp_blocked_until_{$type}_".sha1($value);
        $blockedUntil = Cache::get($blockedUntilKey);

        if ($blockedUntil && now()->timestamp < $blockedUntil) {
            return $blockedUntil - now()->timestamp;
        }

        $countKey = "fp_count_{$type}_".sha1($value);
        $count = Cache::get($countKey, 0);

        if ($count >= $maxRequests) {
            $levelKey = "fp_level_{$type}_".sha1($value);
            $level = Cache::get($levelKey, 0) + 1;
            Cache::put($levelKey, $level, now()->addDays(2)); // Track level for 2 days

            // Escalating windows: 15m (900s) -> 1h (3600s) -> 24h (86400s)
            $duration = 900;
            if ($level == 2) {
                $duration = 3600;
            } elseif ($level >= 3) {
                $duration = 86400;
            }

            $blockedUntil = now()->timestamp + $duration;
            Cache::put($blockedUntilKey, $blockedUntil, $duration);
            Cache::forget($countKey); // Reset counter

            return $duration;
        }

        if ($count == 0) {
            Cache::put($countKey, 1, now()->addMinutes(15));
        } else {
            Cache::increment($countKey);
        }

        return null;
    }

    /**
     * Artificially pads response time.
     */
    private function padResponse(float $startTime, int $targetMs): void
    {
        $elapsedMs = (microtime(true) - $startTime) * 1000;
        if ($elapsedMs < $targetMs) {
            usleep(($targetMs - $elapsedMs) * 1000);
        }
    }
}
