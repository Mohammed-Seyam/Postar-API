@extends('emails.layouts.base')

@section('title', 'إعادة تعيين كلمة المرور')
@section('preheader', 'رابط إعادة تعيين كلمة المرور لحسابك في بوستار — صالح لمدة 60 دقيقة.')

@section('content')
    <x-email.heading eyebrow="أمان الحساب">إعادة تعيين كلمة المرور</x-email.heading>

    <p style="margin:0 0 14px;">مرحباً،</p>
    <p style="margin:0;">لقد تلقينا طلباً لإعادة تعيين كلمة المرور الخاصة بحسابك في بوستار. اضغط على الزر أدناه لاختيار كلمة مرور جديدة:</p>

    <x-email.button :href="$resetUrl">إعادة تعيين كلمة المرور</x-email.button>

    <p style="margin:0; font-size:13px; color:#5b5b63;">
        <strong style="color:#27272a;">صلاحية الرابط:</strong> هذا الرابط صالح لمدة 60 دقيقة فقط، ويُستخدم مرة واحدة.
    </p>

    <x-email.callout variant="warning">
        إذا لم تطلب إعادة تعيين كلمة المرور، يمكنك تجاهل هذا البريد بأمان؛ سيبقى حسابك كما هو.
    </x-email.callout>

    <p style="margin:0; font-size:12px; line-height:1.7; color:#5b5b63;">
        إذا لم يعمل الزر، انسخ هذا الرابط والصقه في متصفحك:<br>
        <a href="{{ $resetUrl }}" dir="ltr" style="color:#8656b1; word-break:break-all;">{{ $resetUrl }}</a>
    </p>
@endsection

@section('footer_note', 'هذا البريد مرسل تلقائياً من منصة بوستار.')
