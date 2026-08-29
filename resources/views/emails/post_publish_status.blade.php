<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>حالة النشر</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f3f4f6;
            margin: 0;
            padding: 20px;
            direction: rtl;
            text-align: right;
        }
        .container {
            background-color: #ffffff;
            border-radius: 8px;
            margin: 0 auto;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            max-width: 600px;
            direction: rtl;
            text-align: right;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .header h1 {
            color: {{ $status === 'published' ? '#10b981' : '#ef4444' }};
        }
        .video-info {
            background-color: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .thumbnail {
            width: 90px;
            height: 90px;
            object-fit: cover;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .details h3 {
            margin: 0 0 8px 0;
            color: #111827;
            font-size: 18px;
        }
        .details p {
            margin: 0;
            color: #6b7280;
            font-size: 14px;
        }
        .target {
            padding: 20px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .target h4 {
            margin: 0 0 15px 0;
            color: #374151;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            margin-right: 15px;
            display: inline-block;
        }
        .status-published { background-color: #d1fae5; color: #065f46; }
        .status-failed { background-color: #fee2e2; color: #991b1b; }
        .error { color: #dc2626; font-size: 14px; margin-top: 10px; }
        
        .link-box {
            margin-top: 20px;
            background: #f3f4f6;
            padding: 16px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        .link-text {
            color: #374151;
            text-decoration: none;
            word-break: break-all;
            display: block;
            margin-bottom: 12px;
            margin-top: 8px;
            font-family: monospace;
            font-size: 14px;
            background: #ffffff;
            padding: 12px;
            border-radius: 6px;
            border: 1px dashed #cbd5e1;
            text-align: left;
            direction: ltr;
        }
        .copy-hint {
            font-size: 13px;
            color: #4b5563;
            display: block;
            font-weight: 600;
        }
        .btn {
            display: inline-block;
            background-color: #4f46e5;
            color: #ffffff !important;
            padding: 12px 24px;
            text-decoration: none !important;
            border-radius: 8px;
            margin-top: 30px;
            text-align: center;
            font-weight: bold;
            transition: background-color 0.3s;
        }
        .btn:hover {
            background-color: #4338ca;
        }
        .btn-view {
            background-color: #10b981;
            color: #ffffff !important;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none !important;
            font-size: 14px;
            display: inline-block;
            border: none;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.3s;
        }
        .btn-view:hover {
            background-color: #059669;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            @if($status === 'published')
                <h1>تم النشر بنجاح</h1>
                <p>تم نشر {{ $mediaLabel['noun'] }} {{ $mediaLabel['possessive'] }} بك بنجاح على الحسابات المحددة.</p>
            @else
                <h1>فشل في النشر</h1>
                <p>واجهنا مشكلة أثناء محاولة نشر {{ $mediaLabel['noun'] }} {{ $mediaLabel['possessive'] }} بك.</p>
            @endif
        </div>

        <div class="video-info">
            @if($thumbUrl)
                <img src="{{ $thumbUrl }}" alt="Thumbnail" class="thumbnail">
            @endif
            <div class="details">
                <h3>{{ $post->title ? \Illuminate\Support\Str::limit($post->title, 50) : $mediaLabel['noun'].' بدون عنوان' }}</h3>
                <p style="color: #6b7280; font-size: 14px;">تم النشر في: {{ $publishTime }}</p>
            </div>
        </div>

        <h3>تفاصيل الحسابات:</h3>
        @foreach($post->targets as $target)
            <div class="target">
                <h4>
                    <span>{{ ucfirst($target->socialAccount->platform) }} - {{ $target->socialAccount->username ?? $target->socialAccount->account_name }}</span>
                    <span class="status-badge status-{{ $target->status }}">
                        {{ $target->status === 'published' ? 'تم النشر' : ($target->status === 'failed' ? 'فشل' : 'جاري المعالجة') }}
                    </span>
                </h4>

                @if($target->status === 'failed' && $target->error_message)
                    <div class="error">
                        <strong>السبب:</strong> {{ $target->error_message }}
                    </div>
                @endif

                @if($target->status === 'published' && $target->platform_post_id)
                    @if($target->socialAccount->platform === 'tiktok')
                        @php
                            $tiktokPathSegment = $mediaKind === 'image' ? 'photo' : 'video';
                            $tiktokLink = "https://www.tiktok.com/@{$target->socialAccount->username}/{$tiktokPathSegment}/{$target->platform_post_id}";
                        @endphp
                        <div class="link-box">
                            <span class="copy-hint">{{ $mediaKind === 'image' ? 'رابط الصور:' : 'رابط الفيديو:' }}</span>
                            <div class="link-text">{{ $tiktokLink }}</div>
                            <a href="{{ $tiktokLink }}" target="_blank" class="btn-view" style="color: #ffffff !important; text-decoration: none !important;">فتح الرابط</a>
                        </div>
                    @elseif($target->socialAccount->platform === 'youtube')
                        <div class="link-box">
                            <span class="copy-hint">رابط الفيديو:</span>
                            <div class="link-text">https://www.youtube.com/watch?v={{ $target->platform_post_id }}</div>
                            <a href="https://www.youtube.com/watch?v={{ $target->platform_post_id }}" target="_blank" class="btn-view" style="color: #ffffff !important; text-decoration: none !important;">فتح الرابط</a>
                        </div>
                    @endif
                @endif
            </div>
        @endforeach

        <div style="text-align: center;">
            <a href="{{ config('app.frontend_url', 'https://postar.io') }}/dashboard" class="btn" style="color: #ffffff !important; text-decoration: none !important;">الانتقال إلى لوحة التحكم</a>
        </div>
    </div>
</body>
</html>
