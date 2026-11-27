@extends('emails.layouts.base')

@section('title', 'رمز التحقق')
@section('preheader', 'رمز التحقق الخاص بك '.$action.': '.$code)

@section('content')
    <x-email.heading eyebrow="رمز التحقق" :lead="'استخدم هذا الرمز '.$action.'.'">رمز التحقق الخاص بك</x-email.heading>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
            <td align="center" style="padding:22px 16px; background-color:#f9f6ff; border:1px solid #dfd3f0; border-radius:16px;">
                <span dir="ltr" style="font-family:Consolas, Menlo, 'Courier New', monospace; font-size:34px; font-weight:700; letter-spacing:10px; color:#0b0b0f;">{{ $code }}</span>
            </td>
        </tr>
    </table>

    <p style="margin:16px 0 0; font-size:13px; color:#5b5b63; text-align:center;">ينتهي الرمز خلال {{ $minutes }} دقيقة.</p>

    <x-email.callout :variant="$destructive ? 'danger' : 'warning'">
        <strong>تنبيه أمني هام:</strong> لا تشارك هذا الرمز مع أي شخص على الإطلاق.
        @if ($destructive)
            إدخال هذا الرمز يحذف حسابك وكل بياناتك نهائياً.
        @endif
        إذا لم تطلب هذا الرمز، يمكنك تجاهل هذا البريد.
    </x-email.callout>
@endsection

@section('footer_note', 'هذا البريد مرسل تلقائياً من منصة بوستار.')
