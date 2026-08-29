<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(protected AuthService $authService) {}

    public function sendRegisterCode(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $code = random_int(100000, 999999);

        // Cache registration details for 15 minutes
        \Illuminate\Support\Facades\Cache::put('register_'.sha1($validated['email']), [
            'code' => $code,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => \Illuminate\Support\Facades\Crypt::encrypt($validated['password']),
        ], now()->addMinutes(15));

        try {
            \Illuminate\Support\Facades\Mail::raw(
                "رمز التحقق الخاص بك لإنشاء الحساب هو: {$code}\n\nتنبيه أمني هام: لا تشارك هذا الرمز مع أي شخص على الإطلاق.",
                function ($message) use ($validated) {
                    $message->to($validated['email'])
                        ->subject('رمز التحقق لإنشاء حسابك');
                }
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send registration verification code: '.$e->getMessage());
        }

        return response()->json(['message' => 'Verification code sent successfully']);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
        ]);

        $cacheKey = 'register_'.sha1($validated['email']);
        $attemptsKey = 'register_attempts_'.sha1($validated['email']);
        $cachedData = \Illuminate\Support\Facades\Cache::get($cacheKey);

        // Limit code-guessing: after 5 wrong tries, invalidate the code entirely.
        $attempts = \Illuminate\Support\Facades\Cache::get($attemptsKey, 0);
        if ($attempts >= 5) {
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
            throw \Illuminate\Validation\ValidationException::withMessages([
                'code' => ['لقد تجاوزت عدد المحاولات المسموح بها. يرجى طلب رمز جديد.'],
            ]);
        }

        if (! $cachedData || ! hash_equals((string) $cachedData['code'], (string) $validated['code'])) {
            \Illuminate\Support\Facades\Cache::put($attemptsKey, $attempts + 1, now()->addMinutes(15));
            throw \Illuminate\Validation\ValidationException::withMessages([
                'code' => ['رمز التحقق غير صحيح أو انتهت صلاحيته.'],
            ]);
        }

        \Illuminate\Support\Facades\Cache::forget($attemptsKey);

        $user = $this->authService->register([
            'name' => $cachedData['name'],
            'email' => $cachedData['email'],
            'password' => \Illuminate\Support\Facades\Crypt::decrypt($cachedData['password']),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        \Illuminate\Support\Facades\Cache::forget('register_'.sha1($validated['email']));

        // Send Welcome Email
        try {
            \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\WelcomeMail($user));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send welcome email: '.$e->getMessage());
        }

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $this->authService->login($request->validated());

        // Send Login Notification Email
        try {
            $user = $data['user'];
            $ip = $request->ip();
            $userAgent = $request->userAgent();

            $geolocationService = app(\App\Services\GeolocationService::class);
            $location = $geolocationService->getLocation($ip);
            $deviceInfo = $geolocationService->parseUserAgent($userAgent);
            $loginTime = now()->format('Y-m-d h:i A');

            \Illuminate\Support\Facades\Mail::to($user->email)->send(
                new \App\Mail\LoginNotificationMail($user, $ip, $deviceInfo, $location, $loginTime)
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send login notification email: '.$e->getMessage());
        }

        return response()->json([
            'user' => new UserResource($data['user']),
            'token' => $data['token'],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(new UserResource($request->user()));
    }
}
