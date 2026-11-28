@extends('emails.layouts.base')

@php($appUrl = rtrim(config('app.frontend_url', 'http://localhost:4200'), '/'))

@section('title', 'تنبيه أمني: تسجيل دخول جديد')
@section('preheader', 'تم تسجيل دخول جديد إلى حسابك في بوستار. راجع التفاصيل.')

@section('content')
    <x-email.heading eyebrow="تنبيه أمني" lead="رصدنا تسجيل دخول جديداً إلى حسابك في بوستار.">تسجيل دخول جديد</x-email.heading>

    <p style="margin:0 0 14px;">مرحباً <strong style="color:#0b0b0f;">{{ $user->name }}</strong>،</p>
    <p style="margin:0;">تم تسجيل الدخول بنجاح إلى حسابك. راجع تفاصيل الجلسة للتأكد من أنه أنت:</p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:20px 0; background-color:#f4f4f6; border-radius:12px;">
        <tr>
            <td style="padding:6px 18px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="font-family:'Cairo', Tahoma, Arial, sans-serif; font-size:14px;">
                    <tr>
                        <td style="padding:10px 0; color:#5b5b63; border-bottom:1px solid #e7e7ea; width:40%;">الجهاز / المتصفح</td>
                        <td style="padding:10px 0; color:#0b0b0f; font-weight:600; border-bottom:1px solid #e7e7ea;">{{ $deviceInfo['full'] }}</td>
                    </tr>
                    <tr>
                        <td style="padding:10px 0; color:#5b5b63; border-bottom:1px solid #e7e7ea;">الموقع الجغرافي</td>
                        <td style="padding:10px 0; color:#0b0b0f; font-weight:600; border-bottom:1px solid #e7e7ea;">{{ $location }}</td>
                    </tr>
                    <tr>
                        <td style="padding:10px 0; color:#5b5b63; border-bottom:1px solid #e7e7ea;">عنوان الـ IP</td>
                        <td style="padding:10px 0; color:#0b0b0f; font-weight:600; border-bottom:1px solid #e7e7ea; font-family:Consolas, Menlo, monospace;" dir="ltr" align="right">{{ $ip }}</td>
                    </tr>
                    <tr>
                        <td style="padding:10px 0; color:#5b5b63;">التوقيت</td>
                        <td style="padding:10px 0; color:#0b0b0f; font-weight:600;">{{ $loginTime }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <x-email.callout variant="danger">
        <strong>لم تقم بتسجيل الدخول؟</strong>
        <a href="{{ $appUrl }}/app/settings" style="color:#991b1b; font-weight:700;">غيّر كلمة المرور فوراً</a>
        لحماية حسابك من أي وصول غير مصرح به.
    </x-email.callout>

    <x-email.button :href="$appUrl.'/app/dashboard'" variant="secondary">الانتقال إلى لوحة التحكم</x-email.button>
@endsection

@section('footer_note', 'نرسل هذا التنبيه تلقائياً عند أي نشاط جديد على حسابك.')
