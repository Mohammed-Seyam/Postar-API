<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeolocationService
{
    /**
     * Get a user-friendly location string (City, Country) from an IP address.
     */
    public function getLocation(string $ip): string
    {
        // Handle local network loopbacks
        if ($ip === '127.0.0.1' || $ip === '::1' || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')) {
            return 'الشبكة المحلية (Local Network)';
        }

        return Cache::remember("ip_loc_{$ip}", now()->addDays(7), function () use ($ip) {
            try {
                // FreeIPAPI is very fast and reliable with generous limits
                $response = Http::timeout(3)->get("https://freeipapi.com/api/json/{$ip}");
                if ($response->successful()) {
                    $data = $response->json();
                    $city = $data['cityName'] ?? '';
                    $country = $data['countryName'] ?? '';

                    if ($city && $country) {
                        return "{$city}، {$country}";
                    } elseif ($country) {
                        return $country;
                    }
                }
            } catch (\Exception $e) {
                Log::error("Failed to fetch geolocation for IP {$ip}: ".$e->getMessage());
            }

            return 'مكان غير معروف (Unknown Location)';
        });
    }

    /**
     * Parses a raw user-agent string into browser and operating system details.
     */
    public function parseUserAgent(?string $userAgent): array
    {
        if (empty($userAgent)) {
            return [
                'browser' => 'جهاز غير معروف',
                'os' => 'غير معروف',
                'full' => 'جهاز غير معروف (Unknown Device)',
            ];
        }

        $browser = 'متصفح غير معروف';
        $os = 'نظام تشغيل غير معروف';

        // Detect OS
        if (preg_match('/windows|win32/i', $userAgent)) {
            $os = 'Windows';
        } elseif (preg_match('/macintosh|mac os x/i', $userAgent)) {
            $os = 'macOS';
        } elseif (preg_match('/iphone/i', $userAgent)) {
            $os = 'iPhone (iOS)';
        } elseif (preg_match('/ipad/i', $userAgent)) {
            $os = 'iPad (iOS)';
        } elseif (preg_match('/android/i', $userAgent)) {
            $os = 'Android';
        } elseif (preg_match('/linux/i', $userAgent)) {
            $os = 'Linux';
        }

        // Detect browser
        if (preg_match('/MSIE/i', $userAgent) && ! preg_match('/Opera/i', $userAgent)) {
            $browser = 'Internet Explorer';
        } elseif (preg_match('/Trident/i', $userAgent)) {
            $browser = 'Internet Explorer';
        } elseif (preg_match('/Edge/i', $userAgent) || preg_match('/Edg/i', $userAgent)) {
            $browser = 'Microsoft Edge';
        } elseif (preg_match('/Firefox/i', $userAgent)) {
            $browser = 'Mozilla Firefox';
        } elseif (preg_match('/Chrome/i', $userAgent)) {
            $browser = 'Google Chrome';
        } elseif (preg_match('/Safari/i', $userAgent)) {
            $browser = 'Apple Safari';
        } elseif (preg_match('/Opera/i', $userAgent) || preg_match('/OPR/i', $userAgent)) {
            $browser = 'Opera';
        }

        return [
            'browser' => $browser,
            'os' => $os,
            'full' => "{$browser} ({$os})",
        ];
    }
}
