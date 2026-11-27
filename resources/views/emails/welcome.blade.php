@extends('emails.layouts.base')

@php($appUrl = rtrim(config('app.frontend_url', 'http://localhost:4200'), '/'))

@section('title', 'مرحباً بك في بوستار')
@section('preheader', 'حسابك جاهز. ارفع أول فيديو وجدوله على كل منصاتك.')

@section('content')
    <x-email.heading eyebrow="أهلاً بك" lead="منصتك العربية لجدولة ونشر المحتوى القصير.">مرحباً بك في بوستار، {{ $user->name }}!</x-email.heading>

    <p style="margin:0;">سعداء بانضمامك! بوستار يساعدك على نشر فيديوهاتك وإدارة حساباتك بسهولة، وبشكل مؤتمت بالكامل. إليك ما يمكنك فعله الآن:</p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:22px 0 4px;">
        @foreach ([
            ['جدولة ذكية للمنشورات', 'خطط لنشر أسبوعك أو شهرك كاملاً، ودع بوستار يتولى الباقي.'],
            ['تحليلات وإحصاءات دقيقة', 'راقب نمو حساباتك وتفاعل جمهورك مع كل فيديو.'],
            ['إدارة حسابات متعددة', 'اربط TikTok و YouTube و Instagram في مكان واحد.'],
        ] as [$title, $text])
            <tr>
                <td width="36" valign="top" style="width:36px; padding:0 0 16px;">
                    <div style="width:24px; height:24px; line-height:24px; border-radius:7px; background-color:#f9f6ff; color:#8656b1; text-align:center; font-size:13px; font-weight:800;">&#10003;</div>
                </td>
                <td valign="top" style="padding:0 0 16px; font-size:14px; line-height:1.75; color:#5b5b63;">
                    <strong style="display:block; font-size:15px; color:#0b0b0f;">{{ $title }}</strong>
                    {{ $text }}
                </td>
            </tr>
        @endforeach
    </table>

    <x-email.button :href="$appUrl.'/app/dashboard'">ابدأ الآن واستكشف المنصة</x-email.button>
@endsection

@section('footer_note', 'هذا البريد مرسل تلقائياً من منصة بوستار.')
