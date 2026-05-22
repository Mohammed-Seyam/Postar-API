<?php
namespace App\Support;

class LimitMessages
{
    public static function get(string $type, mixed $current, mixed $max, string $plan): array
    {
        return match($type) {
            'posts'     => self::posts($current, $max, $plan),
            'uploads'   => self::uploads($current, $max, $plan),
            'bulk'      => self::bulk($current, $max, $plan),
            'file_size' => self::fileSize($current, $max),
            'accounts'  => self::accounts($current, $max, $plan),
            'analytics' => self::analytics($plan),
            'storage'   => self::storage($current, $max, $plan),
            default     => self::generic(),
        };
    }

    private static function posts($used, $max, $plan): array
    {
        if ($plan === 'free') {
            return [
                'user_message' => "لقد استنفدت جميع المنشورات المجانية {$max} هذا الشهر. قم بالترقية إلى المبدعين لجدولة ما يصل إلى 20 منشورًا شهريًا.",
                'cta_text'     => 'الترقية إلى Creator — $9/شهر',
                'cta_action'   => 'upgrade:creator',
                'upgrade_to'   => 'creator',
            ];
        }
        if ($plan === 'creator') {
            return [
                'user_message' => "لقد استنفدت جميع المنشورات 20 هذا الشهر. قم بالترقية إلى الاحترافيين لجدولة غير محدودة.",
                'cta_text'     => 'الترقية إلى Pro — $19/شهر',
                'cta_action'   => 'upgrade:pro',
                'upgrade_to'   => 'pro',
            ];
        }
        return self::generic();
    }

    private static function uploads($used, $max, $plan): array
    {
        if ($plan === 'free') {
            return [
                'user_message' => "لقد قمت برفع {$max} فيديوهات مجانية هذا الشهر. قم بالترقية إلى المبدعين لرفع 20 فيديو شهريًا.",
                'cta_text'     => 'الترقية إلى Creator — $9/شهر',
                'cta_action'   => 'upgrade:creator',
                'upgrade_to'   => 'creator',
            ];
        }
        if ($plan === 'creator') {
            return [
                'user_message' => "لقد قمت برفع 20 فيديو هذا الشهر. قم بالترقية إلى الاحترافيين لرفع غير محدود.",
                'cta_text'     => 'الترقية إلى Pro — $19/شهر',
                'cta_action'   => 'upgrade:pro',
                'upgrade_to'   => 'pro',
            ];
        }
        return self::generic();
    }

    private static function bulk($count, $max, $plan): array
    {
        if ($plan === 'free') {
            return [
                'user_message' => 'يمكنك رفع فيديو واحد فقط في الباقة المجانية. قم بالترقية للرفع المتعدد.',
                'cta_text'     => 'تفعيل الرفع المتعدد',
                'cta_action'   => 'upgrade:creator',
                'upgrade_to'   => 'creator',
            ];
        }
        if ($plan === 'creator') {
            return [
                'user_message' => "باقة المبدعين تسمح برفع 5 فيديوهات معاً. لقد اخترت {$count}. للترقية حتى 20 استخدم الباقة الاحترافية.",
                'cta_text'     => 'الترقية للرفع حتى 20 فيديو',
                'cta_action'   => 'upgrade:pro',
                'upgrade_to'   => 'pro',
            ];
        }
        return self::generic();
    }

    private static function fileSize($sizeMb, $maxMb): array
    {
        return [
            'user_message' => "حجم الملف هو {$sizeMb} ميجابايت. الحد الأقصى المسموح به هو {$maxMb} ميجابايت. يرجى تصدير الفيديو بجودة أقل.",
            'cta_text'     => 'كيفية تقليل حجم الملف',
            'cta_action'   => 'help:file_size',
            'upgrade_to'   => null,
        ];
    }

    private static function accounts($connected, $max, $plan): array
    {
        if ($plan === 'free') {
            return [
                'user_message' => 'الباقة المجانية تسمح بحساب واحد مرتبط. قم بالترقية لربط حسابين في أي منصة.',
                'cta_text'     => 'ربط حساب ثاني',
                'cta_action'   => 'upgrade:creator',
                'upgrade_to'   => 'creator',
            ];
        }
        if ($plan === 'creator') {
            return [
                'user_message' => 'باقة المبدعين تسمح بحسابين مرتبطين. قم بالترقية لربط 3 حسابات.',
                'cta_text'     => 'ربط الحساب الثالث',
                'cta_action'   => 'upgrade:pro',
                'upgrade_to'   => 'pro',
            ];
        }
        return self::generic();
    }

    private static function analytics($plan): array
    {
        if ($plan === 'free') {
            return [
                'user_message' => 'الإحصائيات متاحة بدءاً من باقة المبدعين. اكتشف كيف تؤدي منشوراتك على كل منصة.',
                'cta_text'     => 'تفعيل الإحصائيات',
                'cta_action'   => 'upgrade:creator',
                'upgrade_to'   => 'creator',
            ];
        }
        if ($plan === 'creator') {
            return [
                'user_message' => 'الإحصائيات المتقدمة (تاريخ 90 يوم، أداء أعمق) متاحة في الباقة الاحترافية.',
                'cta_text'     => 'تفعيل الإحصائيات المتقدمة',
                'cta_action'   => 'upgrade:pro',
                'upgrade_to'   => 'pro',
            ];
        }
        return self::generic();
    }

    private static function storage($usedGb, $maxGb, $plan): array
    {
        if ($plan === 'free') {
            return [
                'user_message' => "المساحة ممتلئة (تم استخدام {$usedGb} جيجابايت من أصل {$maxGb} جيجابايت). قم بالترقية إلى Creator للحصول على 5 جيجابايت.",
                'cta_text'     => 'الترقية إلى Creator',
                'cta_action'   => 'upgrade:creator',
                'upgrade_to'   => 'creator',
            ];
        }
        if ($plan === 'creator') {
            return [
                'user_message' => "المساحة ممتلئة (تم استخدام {$usedGb} جيجابايت من أصل {$maxGb} جيجابايت). قم بالترقية إلى Pro للحصول على 20 جيجابايت.",
                'cta_text'     => 'الترقية إلى Pro',
                'cta_action'   => 'upgrade:pro',
                'upgrade_to'   => 'pro',
            ];
        }
        return self::generic();
    }

    private static function generic(): array
    {
        return [
            'user_message' => 'لقد وصلت إلى الحد الأقصى في باقتك الحالية. قم بالترقية للمتابعة.',
            'cta_text'     => 'عرض الباقات',
            'cta_action'   => 'navigate:/app/settings?tab=plan',
            'upgrade_to'   => null,
        ];
    }
}
