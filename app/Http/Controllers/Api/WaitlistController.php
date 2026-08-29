<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWaitlistRequest;
use App\Jobs\SendWaitlistConfirmationJob;
use App\Mail\WaitlistStatusMail;
use App\Models\WaitlistSignup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class WaitlistController extends Controller
{
    /**
     * Store a new waitlist signup.
     */
    public function store(StoreWaitlistRequest $request)
    {
        $turnstileResponse = $request->input('cf-turnstile-response');
        $secretKey = config('services.waitlist.turnstile_secret_key', env('TURNSTILE_SECRET_KEY'));

        if ($secretKey) {
            // Fail closed: a missing token is an automatic rejection.
            if (empty($turnstileResponse)) {
                return response()->json(['message' => 'Turnstile verification failed.'], 403);
            }

            $verifyResponse = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secretKey,
                'response' => $turnstileResponse,
                'remoteip' => $request->ip(),
            ]);

            if (!$verifyResponse->successful() || !($verifyResponse->json()['success'] ?? false)) {
                return response()->json(['message' => 'Turnstile verification failed.'], 403);
            }
        } elseif (app()->environment('production')) {
            // In production the secret must be configured — never silently accept
            // signups without bot protection.
            Log::error('TURNSTILE_SECRET_KEY is not set in production; rejecting waitlist signup.');

            return response()->json(['message' => 'Turnstile verification is unavailable. Please try again later.'], 503);
        }

        $referralCode = Str::random(8);
        $confirmationToken = Str::random(32);
        $statusToken = Str::random(32);

        $signup = WaitlistSignup::create([
            'email' => $request->email,
            'name' => $request->name,
            'referral_code' => $referralCode,
            'status_token' => $statusToken,
            'referred_by' => $request->referral_code,
            'source' => $request->source,
            'confirmation_token' => $confirmationToken,
        ]);

        SendWaitlistConfirmationJob::dispatch($signup);

        return response()->json([
            'message' => 'Signup successful. Please check your email to confirm.',
            'referral_code' => $referralCode,
        ], 201);
    }

    /**
     * Confirm a waitlist signup.
     */
    public function confirm($token)
    {
        $signup = WaitlistSignup::where('confirmation_token', $token)
            ->whereNull('confirmed_at')
            ->first();

        if (!$signup) {
            // Already confirmed or invalid token
            $existing = WaitlistSignup::where('confirmation_token', $token)->first();
            if ($existing) {
                // If it exists but is confirmed, just redirect to the frontend status page
                $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:4200'));
                return redirect($frontendUrl . '/waitlist/status?token=' . $existing->status_token);
            }
            return response()->json(['message' => 'Invalid or expired confirmation token.'], 400);
        }

        $signup->update([
            'confirmed_at' => now(),
            // Optional: clear the confirmation token if you don't want it used again, 
            // but we might want to keep it to identify the user later or use a different mechanism.
        ]);

        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:4200'));
        return redirect($frontendUrl . '/waitlist/status?token=' . $signup->status_token);
    }

    /**
     * Get the status of a waitlist signup.
     */
    public function status($status_token)
    {
        $signup = WaitlistSignup::where('status_token', $status_token)->first();

        if (!$signup) {
            return response()->json(['message' => 'Signup not found.'], 404);
        }

        if (!$signup->confirmed_at) {
            return response()->json(['message' => 'Email not confirmed yet.'], 403);
        }

        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:4200'));

        return response()->json([
            'position' => $signup->calculatePosition(),
            'referral_count' => $signup->referrals()->whereNotNull('confirmed_at')->count(),
            'referral_link' => rtrim($frontendUrl, '/') . '/waitlist?ref=' . $signup->referral_code,
            'referral_boost' => (int) config('services.waitlist.referral_boost', 10),
        ]);
    }

    /**
     * Resend status link or confirmation email.
     */
    public function resendStatus(Request $request)
    {
        $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $signup = WaitlistSignup::where('email', $request->email)->first();

        if ($signup) {
            if ($signup->confirmed_at) {
                // Send the status link
                Mail::to($signup->email)->queue(new WaitlistStatusMail($signup));
            } else {
                // Resend the confirmation email
                SendWaitlistConfirmationJob::dispatch($signup);
            }
        }

        // Always return success to prevent email enumeration
        return response()->json([
            'message' => 'إذا كان البريد الإلكتروني مسجلاً، فقد أرسلنا لك رابط الحالة أو التأكيد.',
        ]);
    }
}
