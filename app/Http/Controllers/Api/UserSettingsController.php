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
            'settings' => array_merge($defaultSettings, $user->settings ?? [])
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

    public function updateEmail(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ]);

        // In a real app we'd trigger verification here usually
        $user->update(['email' => $validated['email']]);

        return response()->json(['message' => 'Email updated successfully']);
    }

    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|current_password',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

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

        $currentSettings = $user->settings ?? [];
        $user->update([
            'settings' => array_merge($currentSettings, $validated)
        ]);

        return response()->json(['message' => 'Settings updated successfully']);
    }

    public function destroy(Request $request)
    {
        $user = $request->user();
        
        // Revoke all tokens
        $user->tokens()->delete();
        
        // Delete user
        $user->delete();

        return response()->json(['message' => 'Account deleted successfully']);
    }
}
