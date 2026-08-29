<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>أهلاً بك في بوستار</title>
    <style>
        body {
            font-family: 'Cairo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            margin: 0;
            padding: 0;
            direction: rtl;
            text-align: right;
            -webkit-font-smoothing: antialiased;
        }
        .container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 20px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.02);
            overflow: hidden;
            border: 1px solid #f1f5f9;
        }
        .header {
            background: linear-gradient(135deg, #7c3aed, #db2777);
            padding: 45px 30px;
            text-align: center;
            color: #ffffff;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        .header p {
            margin: 10px 0 0 0;
            font-size: 16px;
            opacity: 0.9;
        }
        .content {
            padding: 40px 30px;
        }
        .content p {
            font-size: 16px;
            line-height: 1.8;
            color: #475569;
            margin-bottom: 25px;
        }
        .features {
            margin: 30px 0;
            padding: 0;
            list-style: none;
        }
        .feature-item {
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
        }
        .feature-icon {
            background-color: #f5f3ff;
            color: #7c3aed;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-left: 15px;
            flex-shrink: 0;
            font-weight: bold;
        }
        .feature-text {
            font-size: 15px;
            line-height: 1.6;
            color: #334155;
        }
        .feature-text strong {
            color: #0f172a;
            display: block;
            margin-bottom: 2px;
        }
        .cta-container {
            text-align: center;
            margin: 40px 0 20px 0;
        }
        .btn-primary {
            display: inline-block;
            background: linear-gradient(135deg, #7c3aed, #db2777);
            color: #ffffff !important;
            text-decoration: none;
            padding: 14px 35px;
            border-radius: 12px;
            font-weight: bold;
            font-size: 16px;
            box-shadow: 0 4px 6px -1px rgba(124, 58, 237, 0.2), 0 2px 4px -1px rgba(124, 58, 237, 0.1);
            transition: transform 0.2s ease;
        }
        .footer {
            background-color: #f8fafc;
            padding: 25px 30px;
            text-align: center;
            border-top: 1px solid #f1f5f9;
            font-size: 13px;
            color: #94a3b8;
        }
        .footer a {
            color: #7c3aed;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>مرحباً بك في بوستار، {{ $user->name }}!</h1>
            <p>منصتك الاحترافية لإدارة وجدولة منشوراتك الذكية</p>
        </div>
        <div class="content">
            <p>سعداء جداً بانضمامك إلينا! تم تصميم بوستار لتمكينك من مشاركة محتواك وإدارة حساباتك الاجتماعية بكل سهولة واحترافية وبشكل مؤتمت بالكامل.</p>
            
            <p>إليك نظرة سريعة على ما يمكنك فعله في حسابك الآن:</p>
            
            <ul class="features">
                <li class="feature-item">
                    <span class="feature-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg></span>
                    <div class="feature-text">
                        <strong>جدولة ذكية للمنشورات</strong>
                        خطط لجدول نشر متكامل على مدار الأسبوع أو الشهر ودع بوستار يتولى الباقي.
                    </div>
                </li>
                <li class="feature-item">
                    <span class="feature-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle;"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg></span>
                    <div class="feature-text">
                        <strong>تحليلات وإحصاءات دقيقة</strong>
                        راقب نمو حساباتك وتفاعل جمهورك مع كل منشور لتبني قرارات ذكية.
                    </div>
                </li>
                <li class="feature-item">
                    <span class="feature-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle;"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg></span>
                    <div class="feature-text">
                        <strong>إدارة حسابات متعددة</strong>
                        اربط حساباتك عبر المنصات المختلفة (تيك توك، يوتيوب والمزيد) في مكان واحد.
                    </div>
                </li>
            </ul>

            <div class="cta-container">
                <a href="{{ config('app.frontend_url', 'http://localhost:4200') }}/app/dashboard" class="btn-primary">ابتدئ الآن واستكشف المنصة</a>
            </div>
        </div>
        <div class="footer">
            <p>هذا البريد الإلكتروني مرسل تلقائياً من منصة بوستار.</p>
            <p>إذا كان لديك أي استفسار، لا تتردد في <a href="mailto:support@postar.blog">الاتصال بفريق الدعم</a>.</p>
            <p>&copy; {{ date('Y') }} بوستار (Postar). جميع الحقوق محفوظة.</p>
        </div>
    </div>
</body>
</html>
