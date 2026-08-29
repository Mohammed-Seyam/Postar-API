<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'language' => $data['language'] ?? 'en',
            ]);

            $freePlan = \App\Models\Plan::where('name', 'free')->first();
            if ($freePlan) {
                // plan_id is guarded against mass assignment; set it explicitly.
                $user->forceFill(['plan_id' => $freePlan->id])->save();
            }

            return $user;
        });
    }

    public function login(array $credentials): array
    {
        // Per-account lockout to stop distributed brute force that rotates IPs
        // (the route-level throttle only limits per IP). 5 failures -> 15-min lock.
        $throttleKey = 'login:'.Str::lower($credentials['email']);
        $maxAttempts = 5;
        $decaySeconds = 900;

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            throw ValidationException::withMessages([
                'email' => ["Too many login attempts. Please try again in {$seconds} seconds."],
            ])->status(429);
        }

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey, $decaySeconds);
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        // Successful auth clears the failure counter for this account
        RateLimiter::clear($throttleKey);

        try {
            $token = $user->createToken('auth_token')->plainTextToken;
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'email' => ['Failed to authenticate. Please try again.'],
            ]);
        }

        return [
            'user' => $user,
            'token' => $token,
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }
}
