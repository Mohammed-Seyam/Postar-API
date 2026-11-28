@extends('emails.layouts.base')

@php
    $appUrl = rtrim(config('app.frontend_url', 'https://postar.blog'), '/');
    $published = $status === 'published';
    $postTitle = $post->title ? \Illuminate\Support\Str::limit($post->title, 50) : $mediaLabel['noun'].' بدون عنوان';
    $badge = [
        'published' => ['تم النشر', '#dcfce7', '#15803d'],
        'failed' => ['فشل', '#fee2e2', '#dc2626'],
    ];
@endphp

@section('title', $published ? 'تم النشر بنجاح' : 'فشل في النشر')
@section('preheader', $published ? 'تم نشر '.$postTitle.' على حساباتك.' : 'واجهنا مشكلة أثناء نشر '.$postTitle.'.')

@section('content')
    @if ($published)
        <x-email.heading eyebrow="حالة النشر" :lead="'تم نشر '.$mediaLabel['noun'].' '.$mediaLabel['possessive'].' بك بنجاح على الحسابات المحددة.'">تم النشر بنجاح</x-email.heading>
    @else
        <x-email.heading eyebrow="حالة النشر" :lead="'واجهنا مشكلة أثناء محاولة نشر '.$mediaLabel['noun'].' '.$mediaLabel['possessive'].' بك.'">فشل في النشر</x-email.heading>
    @endif

    {{-- Post summary --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f4f4f6; border-radius:12px;">
        <tr>
            @if ($thumbUrl)
                <td width="72" valign="middle" style="width:72px; padding:14px 14px 14px 0;">
                    <img src="{{ $thumbUrl }}" alt="" width="64" height="64" style="display:block; width:64px; height:64px; border-radius:10px; object-fit:cover;">
                </td>
            @endif
            <td valign="middle" style="padding:14px 16px;">
                <strong style="display:block; font-size:15px; color:#0b0b0f;">{{ $postTitle }}</strong>
                <span style="font-size:13px; color:#5b5b63;">تم النشر في: {{ $publishTime }}</span>
            </td>
        </tr>
    </table>

    <p style="margin:24px 0 10px; font-family:'Cairo', Tahoma, Arial, sans-serif; font-size:16px; font-weight:700; color:#0b0b0f;">تفاصيل الحسابات</p>

    @foreach ($post->targets as $target)
        @php
            $account = $target->socialAccount;
            [$badgeText, $badgeBg, $badgeFg] = $badge[$target->status] ?? ['جاري المعالجة', '#fef3c7', '#a16207'];
            $link = null;
            if ($target->status === 'published' && $target->platform_post_id) {
                if ($account->platform === 'tiktok') {
                    $tiktokPathSegment = $mediaKind === 'image' ? 'photo' : 'video';
                    $link = "https://www.tiktok.com/@{$account->username}/{$tiktokPathSegment}/{$target->platform_post_id}";
                } elseif ($account->platform === 'youtube') {
                    $link = "https://www.youtube.com/watch?v={$target->platform_post_id}";
                }
            }
        @endphp
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 10px; border:1px solid #e7e7ea; border-radius:12px;">
            <tr>
                <td style="padding:14px 16px;">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                        <tr>
                            <td style="font-size:14px; font-weight:600; color:#0b0b0f;">
                                {{ ['tiktok' => 'TikTok', 'youtube' => 'YouTube', 'instagram' => 'Instagram'][$account->platform] ?? ucfirst($account->platform) }} — {{ $account->username ?? $account->account_name }}
                            </td>
                            <td align="left" style="white-space:nowrap;">
                                <span style="display:inline-block; padding:3px 10px; border-radius:999px; background-color:{{ $badgeBg }}; color:{{ $badgeFg }}; font-size:12px; font-weight:700;">{{ $badgeText }}</span>
                            </td>
                        </tr>
                    </table>

                    @if ($target->status === 'failed' && $target->error_message)
                        <p style="margin:10px 0 0; padding:10px 12px; border-radius:10px; background-color:#fee2e2; font-size:13px; line-height:1.7; color:#991b1b;">
                            <strong>السبب:</strong> {{ $target->error_message }}
                        </p>
                    @endif

                    @if ($link)
                        <p style="margin:10px 0 0; font-size:12px; color:#5b5b63;">{{ $account->platform === 'tiktok' && $mediaKind === 'image' ? 'رابط الصور:' : 'رابط الفيديو:' }}</p>
                        <p style="margin:2px 0 0; font-size:12px; line-height:1.6;">
                            <a href="{{ $link }}" target="_blank" dir="ltr" style="color:#8656b1; word-break:break-all; text-decoration:none;">{{ $link }}</a>
                        </p>
                    @endif
                </td>
            </tr>
        </table>
    @endforeach

    <x-email.button :href="$appUrl.'/app/dashboard'">الانتقال إلى لوحة التحكم</x-email.button>
@endsection

@section('footer_note', 'نرسل هذا البريد تلقائياً عند اكتمال كل عملية نشر.')
