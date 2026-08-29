<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تنبيه أمني: تسجيل دخول جديد</title>
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
            background-color: #0f172a;
            padding: 35px 30px;
            text-align: center;
            color: #ffffff;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            color: #f43f5e;
        }
        .header p {
            margin: 10px 0 0 0;
            font-size: 15px;
            color: #94a3b8;
        }
        .content {
            padding: 35px 30px;
        }
        .content p {
            font-size: 15px;
            line-height: 1.7;
            color: #475569;
            margin-bottom: 25px;
        }
        .info-card {
            background-color: #f8fafc;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 20px;
            margin-bottom: 25px;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
        }
        .info-table td {
            padding: 8px 0;
            font-size: 14px;
        }
        .info-label {
            color: #64748b;
            font-weight: 600;
            width: 120px;
        }
        .info-value {
            color: #0f172a;
            font-weight: bold;
        }
        .warning-box {
            background-color: #fff1f2;
            border-right: 4px solid #f43f5e;
            padding: 15px;
            border-radius: 4px 8px 8px 4px;
            margin-bottom: 25px;
        }
        .warning-box p {
            margin: 0;
            font-size: 14px;
            line-height: 1.6;
            color: #be123c;
        }
        .warning-box a {
            color: #e11d48;
            font-weight: bold;
            text-decoration: underline;
        }
        .cta-container {
            text-align: center;
            margin: 30px 0 10px 0;
        }
        .btn-secondary {
            display: inline-block;
            background-color: #0f172a;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 28px;
            border-radius: 10px;
            font-weight: bold;
            font-size: 15px;
            transition: background-color 0.2s ease;
        }
        .btn-secondary:hover {
            background-color: #1e293b;
        }
        .footer {
            background-color: #f8fafc;
            padding: 25px 30px;
            text-align: center;
            border-top: 1px solid #f1f5f9;
            font-size: 12px;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>تنبيه أمني: تسجيل دخول جديد</h1>
            <p>لقد تم رصد نشاط تسجيل دخول جديد لحساب بوستار الخاص بك</p>
        </div>
        <div class="content">
            <p>مرحباً <strong>{{ $user->name }}</strong>،</p>
            <p>نود إعلامك بأنه تم تسجيل الدخول بنجاح إلى حسابك في بوستار. يرجى مراجعة تفاصيل الجلسة أدناه للتأكد من صحة هذا النشاط:</p>
            
            <div class="info-card">
                <table class="info-table">
                    <tr>
                        <td class="info-label">الجهاز / المتصفح:</td>
                        <td class="info-value">{{ $deviceInfo['full'] }}</td>
                    </tr>
                    <tr>
                        <td class="info-label">الموقع الجغرافي:</td>
                        <td class="info-value">{{ $location }}</td>
                    </tr>
                    <tr>
                        <td class="info-label">عنوان الـ IP:</td>
                        <td class="info-value" style="font-family: monospace;">{{ $ip }}</td>
                    </tr>
                    <tr>
                        <td class="info-label">التوقيت:</td>
                        <td class="info-value">{{ $loginTime }}</td>
                    </tr>
                </table>
            </div>

            <div class="warning-box">
                <p>
                    <strong>هل لم تقم بهذا النشاط بنفسك؟</strong><br>
                    إذا لم تكن أنت من قام بتسجيل الدخول، يرجى <a href="{{ config('app.frontend_url', 'http://localhost:4200') }}/app/settings">تغيير كلمة المرور فوراً</a> لحماية حسابك من الوصول غير المصرح به.
                </p>
            </div>

            <div class="cta-container">
                <a href="{{ config('app.frontend_url', 'http://localhost:4200') }}/app/dashboard" class="btn-secondary">الانتقال للوحة التحكم</a>
            </div>
        </div>
        <div class="footer">
            <p>هذا البريد مرسل تلقائياً لحماية حسابك وتنبيهك عند حدوث أي نشاط جديد.</p>
            <p>&copy; {{ date('Y') }} بوستار (Postar). جميع الحقوق محفوظة.</p>
        </div>
    </div>
</body>
</html>
