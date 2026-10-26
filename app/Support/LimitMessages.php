<?php

namespace App\Support;

use App\Models\Plan;

class LimitMessages
{
    public static function get(string $type, mixed $current, mixed $max, string $plan): array
    {
        return match ($type) {
            'videos', 'posts', 'uploads' => self::videos($current, $max, $plan),
            'bulk' => self::bulk($current, $max, $plan),
            'file_size' => self::fileSize($current, $max),
            'accounts' => self::accounts($current, $max, $plan),
            'analytics' => self::analytics($plan),
            'storage' => self::storage($current, $max, $plan),
            default => self::generic(),
        };
    }

    /** Upgrade path: free → creator → pro. */
    private static function nextPlan(string $plan): ?Plan
    {
        return match ($plan) {
            'free' => Plan::byName('creator'),
            'creator' => Plan::byName('pro'),
            default => null,
        };
    }

    /** Arabic counting: 3–10 take the plural form, everything else the singular. */
    private static function videosLabel(int $n): string
    {
        return $n >= 3 && $n <= 10 ? "{$n} فيديوهات" : "{$n} فيديو";
    }

    private static function upgradeCta(Plan $next): array
    {
        $price = rtrim(rtrim(number_format((float) $next->price_monthly, 2, '.', ''), '0'), '.');

        return [
            'cta_text' => "الترقية إلى {$next->display_name} — \${$price}/شهر",
            'cta_action' => 'upgrade:'.$next->name,
            'upgrade_to' => $next->name,
        ];
    }

    private static function videos($used, $max, $plan): array
    {
        $next = self::nextPlan($plan);

        if ($next) {
            return [
                'user_message' => 'وصلت لحد '.self::videosLabel((int) $max).' هذا الشهر. رقّي لخطة '.$next->display_name.' لنشر حتى '.self::videosLabel($next->videos_per_month).'.',
            ] + self::upgradeCta($next);
        }

        if ($plan === 'pro') {
            return [
                'user_message' => 'وصلت لحد '.self::videosLabel((int) $max).' هذا الشهر (حد الاستخدام العادل في خطة المحترف). يتجدد رصيدك مع بداية الشهر القادم.',
            ] + self::generic();
        }

        return self::generic();
    }

    private static function bulk($count, $max, $plan): array
    {
        $next = self::nextPlan($plan);
        if (! $next) {
            return self::generic();
        }

        $message = (int) $max <= 1
            ? 'يمكنك رفع فيديو واحد فقط في المرة الواحدة في خطتك الحالية.'
            : 'خطتك الحالية تسمح برفع '.self::videosLabel((int) $max)." معاً. لقد اخترت {$count}.";

        return [
            'user_message' => $message.' رقّي لخطة '.$next->display_name.' لرفع حتى '.self::videosLabel($next->bulk_upload_limit).' معاً.',
        ] + self::upgradeCta($next);
    }

    private static function fileSize($sizeMb, $maxMb): array
    {
        return [
            'user_message' => "حجم الملف هو {$sizeMb} ميجابايت. الحد الأقصى المسموح به هو {$maxMb} ميجابايت. يرجى تصدير الفيديو بجودة أقل.",
            'cta_text' => 'كيفية تقليل حجم الملف',
            'cta_action' => 'help:file_size',
            'upgrade_to' => null,
        ];
    }

    private static function accounts($connected, $max, $plan): array
    {
        $next = self::nextPlan($plan);

        if ($next) {
            return [
                'user_message' => "وصلت للحد الأقصى من الحسابات المرتبطة في خطتك ({$max}). رقّي لخطة {$next->display_name} لربط حتى {$next->max_social_accounts} حسابات.",
            ] + self::upgradeCta($next);
        }

        if ($plan === 'pro') {
            return [
                'user_message' => "وصلت للحد الأقصى من الحسابات المرتبطة في خطة المحترف ({$max}).",
            ] + self::generic();
        }

        return self::generic();
    }

    private static function analytics($plan): array
    {
        $pro = Plan::byName('pro');
        if (! $pro || $plan === 'pro') {
            return self::generic();
        }

        return [
            'user_message' => 'لوحة التحليلات متاحة في خطة المحترف. اكتشف كيف تؤدي فيديوهاتك على كل منصة.',
        ] + self::upgradeCta($pro);
    }

    private static function storage($usedGb, $maxGb, $plan): array
    {
        $next = self::nextPlan($plan);
        if (! $next) {
            return self::generic();
        }

        return [
            'user_message' => "المساحة ممتلئة (تم استخدام {$usedGb} جيجابايت من أصل {$maxGb} جيجابايت). رقّي لخطة {$next->display_name} للحصول على {$next->storage_gb} جيجابايت.",
        ] + self::upgradeCta($next);
    }

    private static function generic(): array
    {
        return [
            'user_message' => 'لقد وصلت إلى الحد الأقصى في باقتك الحالية. قم بالترقية للمتابعة.',
            'cta_text' => 'عرض الباقات',
            'cta_action' => 'navigate:/app/settings?tab=plan',
            'upgrade_to' => null,
        ];
    }
}
