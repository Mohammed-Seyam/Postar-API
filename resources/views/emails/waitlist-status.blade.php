@extends('emails.layouts.base')

@section('title', 'حالتك في قائمة انتظار بوستار')
@section('preheader', 'رابط متابعة مركزك وعدد إحالاتك في قائمة الانتظار.')

@section('content')
    <x-email.heading eyebrow="قائمة الانتظار">رابط حالتك في القائمة</x-email.heading>

    <p style="margin:0;">طلبت رابط حالة قائمة الانتظار الخاص بك. تابع مركزك وعدد إحالاتك عبر الزر أدناه:</p>

    <x-email.button :href="$statusUrl">تحقق من حالتك الآن</x-email.button>

    <p style="margin:0; font-size:12px; line-height:1.7; color:#5b5b63;">
        إذا لم يعمل الزر، انسخ هذا الرابط والصقه في متصفحك:<br>
        <a href="{{ $statusUrl }}" dir="ltr" style="color:#8656b1; word-break:break-all;">{{ $statusUrl }}</a>
    </p>

    <div dir="ltr" style="text-align:left; margin-top:22px; padding-top:20px; border-top:1px solid #e7e7ea; font-family:Inter, Arial, sans-serif; font-size:14px; line-height:1.7; color:#5b5b63;">
        You requested your waitlist status link. <a href="{{ $statusUrl }}" style="color:#8656b1;">Check your current position</a>.
    </div>
@endsection
