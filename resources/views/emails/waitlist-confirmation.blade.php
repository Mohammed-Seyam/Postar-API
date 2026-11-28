@extends('emails.layouts.base')

@section('title', 'أكّد بريدك — قائمة انتظار بوستار')
@section('preheader', 'خطوة واحدة لتأمين مكانك في قائمة انتظار بوستار.')

@section('content')
    <x-email.heading eyebrow="قائمة الانتظار">أهلاً بك في قائمة انتظار بوستار!</x-email.heading>

    <p style="margin:0;">اقتربت خطوة من أسهل طريقة لجدولة ونشر محتواك. أكّد بريدك الإلكتروني لتأمين مكانك في القائمة، ثم ادعُ أصدقاءك للتقدم في الترتيب.</p>

    <x-email.button :href="$confirmationUrl">تأكيد البريد الإلكتروني</x-email.button>

    <div dir="ltr" style="text-align:left; padding-top:20px; border-top:1px solid #e7e7ea; font-family:Inter, Arial, sans-serif;">
        <p style="margin:0 0 6px; font-size:15px; font-weight:700; color:#0b0b0f;">Welcome to the Postar waitlist!</p>
        <p style="margin:0; font-size:14px; line-height:1.7; color:#5b5b63;">You're one step closer to the easiest way to schedule and publish your short-form content. Confirm your email to secure your spot and start inviting friends to move up the queue.</p>
    </div>
@endsection
