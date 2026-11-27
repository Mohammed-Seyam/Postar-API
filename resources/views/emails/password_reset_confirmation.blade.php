@extends('emails.layouts.base')

@section('title', 'تم تغيير كلمة المرور')
@section('preheader', 'تم تغيير كلمة المرور لحسابك في بوستار بنجاح.')

@section('content')
    <x-email.heading eyebrow="أمان الحساب">تم تغيير كلمة المرور بنجاح</x-email.heading>

    <p style="margin:0 0 14px;">مرحباً،</p>
    <p style="margin:0;">نود إشعارك بأنه تم تغيير كلمة المرور الخاصة بحسابك في بوستار بنجاح.</p>

    <x-email.callout variant="warning">
        <strong>لم تقم بهذا التغيير؟</strong> تواصل معنا فوراً على
        <a href="mailto:support@postar.blog" style="color:#713f12; font-weight:700;"><span dir="ltr">support@postar.blog</span></a>
        <br>
        <span dir="ltr" style="display:block; text-align:left; margin-top:6px; color:#713f12;">Your Postar password was just changed. If this wasn't you, contact us at support@postar.blog immediately.</span>
    </x-email.callout>

    <p style="margin:0;">إذا كنت أنت من قام بهذا التغيير، يمكنك متابعة استخدام حسابك بكلمة المرور الجديدة. أنهينا جميع جلسات تسجيل الدخول الأخرى لضمان أمان حسابك.</p>
@endsection

@section('footer_note', 'هذا البريد مرسل تلقائياً لأسباب أمنية من منصة بوستار.')
