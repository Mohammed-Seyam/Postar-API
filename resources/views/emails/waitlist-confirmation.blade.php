<!DOCTYPE html>
<html lang="en" dir="auto">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Your Waitlist Spot</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f4f7f6; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { color: #2563eb; font-size: 24px; margin: 0; }
        .content { text-align: center; line-height: 1.6; }
        .btn { display: inline-block; background-color: #2563eb; color: #ffffff; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 20px 0; }
        .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #888; }
        .bilingual { margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Postar</h1>
        </div>
        <div class="content">
            <div class="bilingual" dir="rtl">
                <h2>أهلاً بك في قائمة انتظار بوستار!</h2>
                <p>لقد اقتربت خطوة واحدة من أسهل طريقة لجدولة ونشر محتواك. يرجى تأكيد بريدك الإلكتروني لتأمين مكانك في القائمة والبدء في دعوة أصدقائك للتقدم في الترتيب.</p>
            </div>
            
            <a href="{{ $confirmationUrl }}" class="btn">تأكيد البريد الإلكتروني / Confirm Email</a>
            
            <div class="bilingual" dir="ltr">
                <h2>Welcome to the Postar Waitlist!</h2>
                <p>You're one step closer to the easiest way to schedule and publish your short-form content. Please confirm your email to secure your spot and start inviting friends to move up the queue.</p>
            </div>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} Postar. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
