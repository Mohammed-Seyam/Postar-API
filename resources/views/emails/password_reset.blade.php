<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعادة تعيين كلمة المرور</title>
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
        .cta-container {
            text-align: center;
            margin: 35px 0;
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
        .warning-box {
            background-color: #fef2f2;
            border-right: 4px solid #ef4444;
            padding: 15px 20px;
            border-radius: 8px;
            margin: 30px 0;
        }
        .warning-box p {
            margin: 0;
            font-size: 14px;
            color: #991b1b;
            line-height: 1.6;
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
            color: #7c3aed;
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
            .btn-primary {
                width: 100%;
                box-sizing: border-box;
                padding: 14px 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>إعادة تعيين كلمة المرور</h1>
            <p>Password Reset Request</p>
        </div>
        <div class="content">
            <p>مرحباً،</p>
            <p>لقد تلقينا طلباً لإعادة تعيين كلمة المرور الخاصة بحسابك في بوستار (Postar). يمكنك إعادة تعيين كلمة المرور من خلال الضغط على الزر أدناه:</p>
            
            <div class="cta-container">
                <a href="{{ $resetUrl }}" class="btn-primary">إعادة تعيين كلمة المرور</a>
            </div>

            <p style="font-size: 14px; color: #64748b; margin-top: 10px;">
                <strong>صلاحية الرابط:</strong> هذا الرابط صالح لمدة 60 دقيقة فقط من تاريخ الطلب وينتهي تلقائياً بعد ذلك.
            </p>

            <div class="warning-box">
                <p>إذا لم تكن قد طلبت إعادة تعيين كلمة المرور الخاصة بك، فيمكنك تجاهل هذا البريد الإلكتروني بأمان وسيظل حسابك آمناً.</p>
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
