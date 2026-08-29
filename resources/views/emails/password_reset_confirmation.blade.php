<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تحديث أمني: تغيير كلمة المرور</title>
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
            background: linear-gradient(135deg, #10b981, #059669); /* Secure Emerald Green */
            padding: 45px 30px;
            text-align: center;
            color: #ffffff;
        }
        .header h1 {
            margin: 0;
            font-size: 26px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        .header p {
            margin: 10px 0 0 0;
            font-size: 15px;
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
        .warning-box {
            background-color: #fffbeb;
            border-right: 4px solid #f59e0b;
            padding: 15px 20px;
            border-radius: 8px;
            margin: 30px 0;
        }
        .warning-box p {
            margin: 0;
            font-size: 15px;
            color: #b45309;
            line-height: 1.6;
            font-weight: bold;
        }
        .footer {
            background-color: #f8fafc;
            padding: 25px 30px;
            text-align: center;
            border-top: 1px solid #f1f5f9;
            font-size: 13px;
            color: #94a3b8;
            line-height: 1.6;
        }
        .footer a {
            color: #10b981;
            text-decoration: none;
        }
        /* Mobile styling */
        @media only screen and (max-width: 600px) {
            .container {
                margin: 0 auto;
                border-radius: 0;
                border: none;
            }
            .header {
                padding: 35px 20px;
            }
            .content {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>تم تغيير كلمة المرور بنجاح</h1>
            <p>Password Reset Successful</p>
        </div>
        <div class="content">
            <p>مرحباً،</p>
            <p>نود إشعارك بأنه تم تغيير كلمة المرور الخاصة بحسابك في بوستار (Postar) بنجاح.</p>
            
            <div class="warning-box">
                <p>Your Postar password was just changed. If this wasn't you, contact us at <a href="mailto:postarblog0@gmail.com" style="color: #b45309;">postarblog0@gmail.com</a> immediately.</p>
            </div>
            
            <p>إذا كنت أنت من قام بهذا التغيير، فيمكنك الاستمرار في استخدام حسابك بشكل طبيعي مع كلمة المرور الجديدة. تم إنهاء جميع جلسات تسجيل الدخول النشطة الأخرى لضمان أمان حسابك.</p>
        </div>
        <div class="footer">
            <p>هذا البريد الإلكتروني مرسل تلقائياً لأسباب أمنية من منصة بوستار.</p>
            <p>إذا كان لديك أي استفسار، لا تتردد في <a href="mailto:support@postar.blog">الاتصال بفريق الدعم</a>.</p>
            <p>&copy; {{ date('Y') }} بوستار (Postar). جميع الحقوق محفوظة.</p>
        </div>
    </div>
</body>
</html>
