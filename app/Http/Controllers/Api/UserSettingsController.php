<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserSettingsController extends Controller
{
    public function getSettings(Request $request)
    {
        $user = $request->user();

        $defaultSettings = [
            'timezone' => 'Africa/Cairo',
            'time_format' => '12h',
            'email_notifications' => true,
            'default_visibility' => 'public',
            'watermark_path' => null,
        ];

        return response()->json([
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'settings' => array_merge($defaultSettings, $user->settings ?? []),
            'current_session' => [
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
            ],
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        $user->update($validated);

        return response()->json(['message' => 'Profile updated successfully']);
    }

    /**
     * Step 1: send a verification code to the NEW email address to prove the user
     * controls it before we switch the account over to it.
     */
    public function sendEmailChangeCode(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'email' => ['required', 'string', new \App\Rules\RealEmail, 'max:255', Rule::unique('users')->ignore($user->id)],
        ]);

        $newEmail = strtolower(trim($validated['email']));
        $code = random_int(100000, 999999);

        \Illuminate\Support\Facades\Cache::put("email_change_{$user->id}", [
            'code' => $code,
            'email' => $newEmail,
        ], now()->addMinutes(10));
        \Illuminate\Support\Facades\Cache::forget("email_change_attempts_{$user->id}");

        try {
            \Illuminate\Support\Facades\Mail::raw(
                "رمز التحقق الخاص بك لتغيير البريد الإلكتروني هو: {$code}\n\nتنبيه أمني هام: لا تشارك هذا الرمز مع أي شخص على الإطلاق.",
                function ($message) use ($newEmail) {
                    $message->to($newEmail)
                        ->subject('رمز التحقق لتغيير البريد الإلكتروني');
                }
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send email change code: '.$e->getMessage());
        }

        return response()->json(['message' => 'Verification code sent to the new email address']);
    }

    /**
     * Step 2: verify the code sent to the new address, then apply the change.
     */
    public function updateEmail(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $newEmail = strtolower(trim($validated['email']));
        $cached = \Illuminate\Support\Facades\Cache::get("email_change_{$user->id}");
        $attemptsKey = "email_change_attempts_{$user->id}";
        $attempts = \Illuminate\Support\Facades\Cache::get($attemptsKey, 0);

        if ($attempts >= 5) {
            \Illuminate\Support\Facades\Cache::forget("email_change_{$user->id}");

            return response()->json([
                'message' => 'لقد تجاوزت عدد المحاولات المسموح بها. يرجى طلب رمز جديد.',
                'errors' => ['code' => ['لقد تجاوزت عدد المحاولات المسموح بها. يرجى طلب رمز جديد.']],
            ], 429);
        }

        if (! $cached || $cached['email'] !== $newEmail || ! hash_equals((string) $cached['code'], (string) $validated['code'])) {
            \Illuminate\Support\Facades\Cache::put($attemptsKey, $attempts + 1, now()->addMinutes(10));

            return response()->json([
                'message' => 'رمز التحقق غير صحيح أو انتهت صلاحيته.',
                'errors' => ['code' => ['رمز التحقق غير صحيح أو انتهت صلاحيته.']],
            ], 422);
        }

        $user->update(['email' => $newEmail]);

        \Illuminate\Support\Facades\Cache::forget("email_change_{$user->id}");
        \Illuminate\Support\Facades\Cache::forget($attemptsKey);

        return response()->json(['message' => 'Email updated successfully']);
    }

    public function sendPasswordVerificationCode(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => 'required|current_password',
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

        $code = random_int(100000, 999999);

        // Store hashed password and code in cache for 10 minutes
        \Illuminate\Support\Facades\Cache::put("password_change_{$user->id}", [
            'code' => $code,
            'password' => Hash::make($validated['password']),
        ], now()->addMinutes(10));
        \Illuminate\Support\Facades\Cache::forget("password_change_attempts_{$user->id}");

        // Send email via log mailer/SMTP
        try {
            \Illuminate\Support\Facades\Mail::raw(
                "رمز التحقق الخاص بك لتغيير كلمة المرور هو: {$code}\n\nتنبيه أمني هام: لا تشارك هذا الرمز مع أي شخص على الإطلاق.",
                function ($message) use ($user) {
                    $message->to($user->email)
                        ->subject('رمز التحقق لتغيير كلمة المرور');
                }
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send password change code email: '.$e->getMessage());
        }

        return response()->json(['message' => 'Verification code sent successfully']);
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $cachedData = \Illuminate\Support\Facades\Cache::get("password_change_{$user->id}");
        $attemptsKey = "password_change_attempts_{$user->id}";
        $attempts = \Illuminate\Support\Facades\Cache::get($attemptsKey, 0);

        if ($attempts >= 5) {
            \Illuminate\Support\Facades\Cache::forget("password_change_{$user->id}");

            return response()->json([
                'message' => 'لقد تجاوزت عدد المحاولات المسموح بها. يرجى طلب رمز جديد.',
                'errors' => ['code' => ['لقد تجاوزت عدد المحاولات المسموح بها. يرجى طلب رمز جديد.']],
            ], 429);
        }

        if (! $cachedData || ! hash_equals((string) $cachedData['code'], (string) $validated['code'])) {
            \Illuminate\Support\Facades\Cache::put($attemptsKey, $attempts + 1, now()->addMinutes(10));

            return response()->json([
                'message' => 'رمز التحقق غير صحيح أو انتهت صلاحيته.',
                'errors' => [
                    'code' => ['رمز التحقق غير صحيح أو انتهت صلاحيته.'],
                ],
            ], 422);
        }

        $user->update([
            'password' => $cachedData['password'],
        ]);

        \Illuminate\Support\Facades\Cache::forget("password_change_{$user->id}");
        \Illuminate\Support\Facades\Cache::forget($attemptsKey);

        return response()->json(['message' => 'Password updated successfully']);
    }

    public function updatePreferences(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'timezone' => 'nullable|string',
            'time_format' => 'nullable|in:12h,24h',
            'email_notifications' => 'nullable|boolean',
            'default_visibility' => 'nullable|in:public,private,unlisted',
        ]);

        $settings = $user->settings ?? [];
        foreach ($validated as $key => $value) {
            $settings[$key] = $value;
        }

        $user->settings = $settings;
        $user->save();

        return response()->json(['message' => 'Settings updated successfully']);
    }

    public function sendDeleteAccountCode(Request $request)
    {
        $user = $request->user();
        $code = random_int(100000, 999999);

        // Cache delete code for 10 minutes
        \Illuminate\Support\Facades\Cache::put("delete_account_{$user->id}", $code, now()->addMinutes(10));
        \Illuminate\Support\Facades\Cache::forget("delete_account_attempts_{$user->id}");

        // Send email
        try {
            \Illuminate\Support\Facades\Mail::raw(
                "رمز التحقق الخاص بك لحذف الحساب نهائياً هو: {$code}\n\nتنبيه أمني هام: لا تشارك هذا الرمز مع أي شخص على الإطلاق.",
                function ($message) use ($user) {
                    $message->to($user->email)
                        ->subject('رمز التحقق لحذف الحساب');
                }
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send delete account code email: '.$e->getMessage());
        }

        return response()->json(['message' => 'Verification code sent successfully']);
    }

    public function destroy(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $cachedCode = \Illuminate\Support\Facades\Cache::get("delete_account_{$user->id}");
        $attemptsKey = "delete_account_attempts_{$user->id}";
        $attempts = \Illuminate\Support\Facades\Cache::get($attemptsKey, 0);

        if ($attempts >= 5) {
            \Illuminate\Support\Facades\Cache::forget("delete_account_{$user->id}");

            return response()->json([
                'message' => 'لقد تجاوزت عدد المحاولات المسموح بها. يرجى طلب رمز جديد.',
                'errors' => ['code' => ['لقد تجاوزت عدد المحاولات المسموح بها. يرجى طلب رمز جديد.']],
            ], 429);
        }

        if (! $cachedCode || ! hash_equals((string) $cachedCode, (string) $validated['code'])) {
            \Illuminate\Support\Facades\Cache::put($attemptsKey, $attempts + 1, now()->addMinutes(10));

            return response()->json([
                'message' => 'رمز التحقق غير صحيح أو انتهت صلاحيته.',
                'errors' => [
                    'code' => ['رمز التحقق غير صحيح أو انتهت صلاحيته.'],
                ],
            ], 422);
        }

        // Revoke all tokens
        $user->tokens()->delete();

        // Delete user
        $user->delete();

        \Illuminate\Support\Facades\Cache::forget("delete_account_{$user->id}");
        \Illuminate\Support\Facades\Cache::forget($attemptsKey);

        return response()->json(['message' => 'Account deleted successfully']);
    }
}
